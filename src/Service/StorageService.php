<?php

declare(strict_types=1);

namespace GroceryRestable\Service;

use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Config;
use GroceryRestable\Exception\ApiException;
use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Exception\ValidationException;
use GroceryRestable\Http\UploadedFileInterface;
use GroceryRestable\Resource\Resource;
use GroceryRestable\Store\ConfigStore;

/**
 * File storage buckets, organized like S3 / Cloudflare R2.
 *
 *   - An object is addressed by its key ("users/42/avatar.png"): the file lives at {bucketPath}/{bucket}/{key},
 *     so a bucket folder can be synced 1:1 to S3 / R2 (rclone, aws s3 sync).
 *   - Its metadata (size, ETag = MD5 of the content, Content-Type, original name, Cache-Control, custom
 *     metadata, owner, dates) is indexed in the SQLite store.
 *   - Listing follows ListObjectsV2: prefix, delimiter, limit (max-keys) and cursor (start-after), keys in
 *     binary order, "folders" are common prefixes.
 *   - Writes go to {bucketPath}/.tmp first and are renamed into place (atomic replace).
 *
 * Bucket definition:
 *   ['public' => bool,                 anonymous read access
 *    'allowedTypes' => 'jpg|png|pdf',  null = global upload setting
 *    'maxSize' => '5MB',               null = global upload setting
 *    'permissions' => ['authenticated' => ['select', 'insert', 'update', 'delete']],  null = default permissions
 *    'description' => '']
 */
final class StorageService
{
    public const MAX_LIST = 1000;
    /** Directory of in-flight uploads inside the bucket path (never a bucket: bucket names cannot start with a dot). */
    public const TMP_DIR = '.tmp';
    /** S3 limits: keys up to 1024 bytes, user metadata up to 2 KB. */
    private const MAX_KEY_BYTES = 1024;
    private const MAX_METADATA_BYTES = 2048;

    /** @var array<string, array<string, mixed>> */
    private array $codeBuckets = [];

    public function __construct(
        private readonly Config $config,
        private readonly ConfigStore $store,
        private readonly UploadService $uploads,
    ) {
    }

    // ------------------------------------------------------------------
    // Buckets
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $definition
     */
    public function register(string $name, array $definition): void
    {
        ConfigStore::assertName($name, 'bucket');
        $this->codeBuckets[$name] = ['name' => $name, 'source' => 'code'] + $definition;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buckets(): array
    {
        $buckets = $this->codeBuckets;
        foreach ($this->store->buckets() as $name => $bucket) {
            $buckets[$name] ??= $bucket + ['source' => 'gui'];
        }
        ksort($buckets);

        return $buckets;
    }

    /**
     * @return array<string, mixed>
     */
    public function bucket(string $name): array
    {
        return $this->buckets()[$name] ?? throw ApiException::notFound('Bucket "' . $name . '" does not exist.');
    }

    public function allows(AuthContext $auth, array $bucket, string $permission): bool
    {
        if ($auth->isService()) {
            return true;
        }
        if ($permission === 'select' && ! empty($bucket['public'])) {
            return true;
        }
        $permissions = $bucket['permissions'] ?? null;
        if (! is_array($permissions)) {
            $permissions = (array) $this->store->setting('default_permissions', []);
        }
        $granted = [...(array) ($permissions[$auth->role] ?? []), ...(array) ($permissions['*'] ?? [])];

        return in_array($permission, $granted, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listBuckets(AuthContext $auth): array
    {
        $result = [];
        foreach ($this->buckets() as $name => $bucket) {
            if ($this->allows($auth, $bucket, 'select')) {
                $result[] = [
                    'name' => $name,
                    'public' => (bool) ($bucket['public'] ?? false),
                    'description' => (string) ($bucket['description'] ?? ''),
                    'allowedTypes' => $bucket['allowedTypes'] ?? null,
                    'maxSize' => $bucket['maxSize'] ?? null,
                ];
            }
        }

        return $result;
    }

    public function directory(string $bucketName): string
    {
        ConfigStore::assertName($bucketName, 'bucket');
        $directory = $this->config->bucketPath() . DIRECTORY_SEPARATOR . $bucketName;
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new ConfigurationException('Unable to create bucket directory.');
        }

        return $directory;
    }

    // ------------------------------------------------------------------
    // Objects
    // ------------------------------------------------------------------

    /**
     * ListObjectsV2 style listing.
     *
     *   prefix     raw key prefix ("users/42/" lists that folder)
     *   delimiter  "/" groups deeper keys into folders (common prefixes); "" lists every key recursively
     *   limit      max keys + folders returned (1 - 1000)
     *   cursor     continue after this key or folder (next_cursor of the previous page)
     *
     * @return array<string, mixed>
     */
    public function listObjects(string $bucketName, string $prefix, AuthContext $auth, string $delimiter = '/', int $limit = self::MAX_LIST, string $cursor = ''): array
    {
        $bucket = $this->bucket($bucketName);
        $this->authorize($auth, $bucket, 'select');
        $prefix = ltrim(str_replace('\\', '/', $prefix), '/');
        if ($delimiter !== '' && $delimiter !== '/') {
            throw ValidationException::forField('delimiter', 'Only "/" or an empty delimiter is supported.');
        }
        $limit = max(1, min(self::MAX_LIST, $limit));
        $this->ensureIndexed($bucketName);

        $files = [];
        $prefixes = [];
        // A cursor ending with the delimiter is a folder: continue after everything inside it.
        $after = $delimiter !== '' && str_ends_with($cursor, $delimiter) ? $cursor . "\u{10FFFF}" : $cursor;
        $truncated = false;
        $last = null;
        while (! $truncated) {
            $rows = $this->store->objects($bucketName, $prefix, $after, 200);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $after = $row['path'];
                $rest = substr($row['path'], strlen($prefix));
                $cut = $delimiter !== '' ? strpos($rest, $delimiter) : false;
                if (count($files) + count($prefixes) >= $limit) {
                    $truncated = true;
                    break;
                }
                if ($cut !== false) {
                    // Folder (common prefix): emit once, then jump past every key inside it.
                    $common = $prefix . substr($rest, 0, $cut + 1);
                    $prefixes[] = $common;
                    $last = $common;
                    $after = $common . "\u{10FFFF}";
                    continue 2;
                }
                $files[] = $this->describe($bucketName, $row);
                $last = $row['path'];
            }
        }

        return [
            'bucket' => $bucketName,
            'prefix' => $prefix,
            'delimiter' => $delimiter,
            // Folder names relative to the prefix (without the trailing delimiter), and full common prefixes.
            'folders' => array_map(static fn (string $p): string => rtrim(substr($p, strlen($prefix)), '/'), $prefixes),
            'prefixes' => $prefixes,
            'files' => $files,
            'count' => count($files) + count($prefixes),
            'truncated' => $truncated,
            'next_cursor' => $truncated ? $last : null,
        ];
    }

    /**
     * Store an uploaded file as {folder}/{name}.{ext}.
     *
     * Options: cacheControl (Cache-Control header served with the object),
     *          metadata (string => string, like x-amz-meta-*, max 2 KB).
     *
     * @param array{cacheControl?: string|null, metadata?: array<string, mixed>|null} $options
     * @return array<string, mixed>
     */
    public function upload(string $bucketName, UploadedFileInterface $file, string $folder, ?string $name, bool $upsert, AuthContext $auth, array $options = []): array
    {
        $bucket = $this->bucket($bucketName);
        $this->authorize($auth, $bucket, 'insert');

        $checked = $this->uploads->validate(
            $file,
            UploadService::parseTypes((string) ($bucket['allowedTypes'] ?? '')),
            isset($bucket['maxSize']) && $bucket['maxSize'] !== '' ? (string) $bucket['maxSize'] : null,
        );
        $cacheControl = self::cacheControl($options['cacheControl'] ?? null);
        $metadata = self::metadata($options['metadata'] ?? null);

        $folder = trim(str_replace('\\', '/', $folder), '/');
        $baseName = $name !== null && $name !== '' ? pathinfo($name, PATHINFO_FILENAME) : pathinfo($file->getClientFilename(), PATHINFO_FILENAME);
        $fileName = UploadService::sanitizeBaseName($baseName) . '.' . $checked['extension'];
        $key = ($folder !== '' ? $this->safeRelative($folder) . '/' : '') . $fileName;
        if (strlen($key) > self::MAX_KEY_BYTES) {
            throw ValidationException::forField('path', 'Object keys are limited to ' . self::MAX_KEY_BYTES . ' bytes.');
        }

        $target = $this->pathOf($bucketName, $key);
        $exists = is_file($target);
        if ($exists) {
            if (! $upsert) {
                throw ApiException::conflict('An object already exists at "' . $key . '". Send upsert=true to replace it.');
            }
            $this->authorize($auth, $bucket, 'update');
        }

        // Write next to the buckets, then rename: readers never see a missing or half written file.
        $tmp = $this->tmpDirectory() . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16));
        $file->moveTo($tmp);
        try {
            $etag = (string) md5_file($tmp);
            $size = (int) filesize($tmp);
            $dir = dirname($target);
            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException('Unable to create the bucket folder.');
            }
            if (! rename($tmp, $target)) {
                throw new \RuntimeException('Unable to store the object.');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        $this->store->putObject($bucketName, $key, [
            'size' => $size,
            'etag' => $etag,
            'content_type' => $checked['mime'],
            'original_name' => mb_substr($file->getClientFilename(), 0, 255),
            'cache_control' => $cacheControl,
            'metadata' => $metadata,
            'owner' => self::owner($auth),
        ]);

        return ['bucket' => $bucketName, 'replaced' => $exists] + $this->describe($bucketName, $this->store->object($bucketName, $key));
    }

    /**
     * Metadata of one object (HeadObject).
     *
     * @return array<string, mixed>
     */
    public function info(string $bucketName, string $path, AuthContext $auth): array
    {
        $bucket = $this->bucket($bucketName);
        $this->authorize($auth, $bucket, 'select');

        return $this->objectMetadata($bucketName, $path);
    }

    /**
     * @return array<string, mixed> path (absolute file), name, download_name, mime, etag, size, last_modified, cache_control
     */
    public function download(string $bucketName, string $path, AuthContext $auth): array
    {
        $bucket = $this->bucket($bucketName);
        $this->authorize($auth, $bucket, 'select');
        $object = $this->objectMetadata($bucketName, $path);
        $public = ! empty($bucket['public']);
        // Private objects must never land in shared caches, whatever their metadata says.
        $cacheControl = $object['cache_control'] !== null && ($public || str_starts_with($object['cache_control'], 'private'))
            ? $object['cache_control']
            : ($public ? 'public, max-age=3600' : 'private, no-store');

        return [
            'path' => $this->existingFile($bucketName, $object['path']),
            'name' => $object['name'],
            'download_name' => $object['original_name'] ?: $object['name'],
            'mime' => $object['mime'],
            'etag' => $object['etag'],
            'size' => $object['size'],
            'last_modified' => $object['updated_at'],
            'cache_control' => $cacheControl,
        ];
    }

    public function delete(string $bucketName, string $path, AuthContext $auth): void
    {
        $bucket = $this->bucket($bucketName);
        $this->authorize($auth, $bucket, 'delete');
        $file = $this->existingFile($bucketName, $path);
        unlink($file);
        $this->store->deleteObject($bucketName, $this->safeRelative($path));
        $this->pruneEmptyFolders($bucketName, dirname($file));
    }

    /**
     * Synchronize the index with the files of a bucket: index files copied in by hand, drop rows whose
     * file is gone. Returns ['indexed' => n, 'removed' => n].
     *
     * @return array{indexed: int, removed: int}
     */
    public function reindex(string $bucketName): array
    {
        $root = $this->directory($bucketName);
        $indexed = 0;
        $seen = [];
        foreach ($this->files($root) as $key => $absolute) {
            $seen[$key] = true;
            if ($this->store->object($bucketName, $key) === null) {
                $this->store->putObject($bucketName, $key, self::fileMetadata($absolute, $this->uploads));
                $indexed++;
            }
        }
        $removed = 0;
        $after = '';
        while (($rows = $this->store->objects($bucketName, '', $after, 500)) !== []) {
            foreach ($rows as $row) {
                $after = $row['path'];
                if (! isset($seen[$row['path']])) {
                    $this->store->deleteObject($bucketName, $row['path']);
                    $removed++;
                }
            }
        }

        return ['indexed' => $indexed, 'removed' => $removed];
    }

    /**
     * Every file of a bucket directory: key => absolute path (hidden files and folders are skipped).
     *
     * @return iterable<string, string>
     */
    public function files(string $root): iterable
    {
        if (! is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => ! str_starts_with($f->getFilename(), '.'),
            ),
        );
        $length = strlen($root) + 1;
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                yield str_replace('\\', '/', substr($file->getPathname(), $length)) => $file->getPathname();
            }
        }
    }

    public function tmpDirectory(): string
    {
        $directory = $this->config->bucketPath() . DIRECTORY_SEPARATOR . self::TMP_DIR;
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create the temporary upload folder.');
        }

        return $directory;
    }

    /**
     * Remove empty folders from $directory up to (excluding) the bucket root. S3 has no folders.
     */
    public function pruneEmptyFolders(string $bucketName, string $directory): void
    {
        $root = realpath($this->directory($bucketName));
        $current = realpath($directory);
        while ($root !== false && $current !== false && $current !== $root && str_starts_with($current, $root . DIRECTORY_SEPARATOR)) {
            if ((new \FilesystemIterator($current))->valid() || ! @rmdir($current)) {
                return;
            }
            $current = dirname($current);
        }
    }

    // ------------------------------------------------------------------

    /**
     * Index the bucket on first use (buckets created before the index, or filled by hand).
     */
    private function ensureIndexed(string $bucketName): void
    {
        if ($this->store->countObjects($bucketName) === 0) {
            $this->reindex($bucketName);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function objectMetadata(string $bucketName, string $path): array
    {
        $key = $this->safeRelative($path);
        $file = $this->existingFile($bucketName, $key);
        $row = $this->store->object($bucketName, $key);
        if ($row === null || (int) $row['size'] !== (int) filesize($file)) {
            // Unknown or changed outside the API: (re)index it.
            $this->store->putObject($bucketName, $key, self::fileMetadata($file, $this->uploads) + ['created_at' => $row['created_at'] ?? null]);
            $row = $this->store->object($bucketName, $key);
        }

        return $this->describe($bucketName, $row);
    }

    /**
     * Public shape of an object.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function describe(string $bucketName, array $row): array
    {
        return [
            'name' => basename($row['path']),
            'path' => $row['path'],
            'size' => (int) $row['size'],
            'mime' => $row['content_type'],
            'etag' => $row['etag'],
            'original_name' => $row['original_name'],
            'cache_control' => $row['cache_control'],
            'metadata' => $row['metadata'] ?: (object) [],
            'owner' => $row['owner'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * Metadata computed from a file on disk.
     *
     * @return array{size: int, etag: string, content_type: string, created_at: string, updated_at: string}
     */
    private static function fileMetadata(string $file, UploadService $uploads): array
    {
        $modified = gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($file));

        return [
            'size' => (int) filesize($file),
            'etag' => (string) md5_file($file),
            'content_type' => $uploads->detectMime($file),
            'created_at' => $modified,
            'updated_at' => $modified,
        ];
    }

    private function pathOf(string $bucketName, string $key): string
    {
        return $this->directory($bucketName) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
    }

    private function existingFile(string $bucketName, string $path): string
    {
        $root = realpath($this->directory($bucketName));
        $candidate = realpath($this->pathOf($bucketName, $this->safeRelative($path)));
        if ($root === false || $candidate === false || ! is_file($candidate) || ! str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)) {
            throw ApiException::notFound('Object not found.');
        }

        return $candidate;
    }

    /**
     * Validate a relative object key ("folder/sub/file.jpg").
     */
    private function safeRelative(string $path): string
    {
        $segments = explode('/', trim(str_replace('\\', '/', $path), '/'));
        if (count($segments) > 10) {
            throw ValidationException::forField('path', 'Paths are limited to 10 levels.');
        }
        foreach ($segments as $segment) {
            if (! UploadService::isSafeFileName($segment)) {
                throw ValidationException::forField('path', 'Invalid path segment "' . $segment . '".');
            }
        }

        return implode('/', $segments);
    }

    private static function cacheControl(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^[A-Za-z0-9=,\- ]{1,200}$/', $value)) {
            throw ValidationException::forField('cache_control', 'Invalid Cache-Control value.');
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function metadata(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $value = json_decode($value, true);
            if (! is_array($value)) {
                throw ValidationException::forField('metadata', 'Metadata must be a JSON object.');
            }
        }
        if (! is_array($value) || $value === []) {
            return [];
        }
        $metadata = [];
        foreach ($value as $key => $item) {
            if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $key) || (! is_scalar($item) && $item !== null)) {
                throw ValidationException::forField('metadata', 'Metadata keys are letters, digits, "_" or "-"; values are text.');
            }
            $metadata[strtolower((string) $key)] = (string) $item;
        }
        if (strlen((string) json_encode($metadata)) > self::MAX_METADATA_BYTES) {
            throw ValidationException::forField('metadata', 'Metadata is limited to 2 KB.');
        }

        return $metadata;
    }

    private static function owner(AuthContext $auth): string
    {
        $user = $auth->userId();

        return match (true) {
            is_scalar($user) && (string) $user !== '' => 'user:' . $user,
            $auth->apiKeyId !== null => 'key:' . $auth->apiKeyId,
            default => 'role:' . $auth->role,
        };
    }

    /**
     * @param array<string, mixed> $bucket
     */
    private function authorize(AuthContext $auth, array $bucket, string $permission): void
    {
        if ($this->allows($auth, $bucket, $permission)) {
            return;
        }
        if ($auth->isAnonymous()) {
            throw ApiException::unauthorized();
        }
        throw ApiException::forbidden(sprintf('Role "%s" is not allowed to %s objects of bucket "%s".', $auth->role, $permission, $bucket['name']));
    }

    /**
     * Validate a bucket definition coming from the GUI.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public static function normalizeDefinition(array $definition): array
    {
        $permissions = null;
        if (isset($definition['permissions']) && is_array($definition['permissions'])) {
            $permissions = [];
            foreach ($definition['permissions'] as $role => $ops) {
                ConfigStore::assertRole((string) $role === '*' ? 'any' : (string) $role);
                $ops = array_values(array_intersect((array) $ops, Resource::PERMISSIONS));
                $permissions[(string) $role] = $ops;
            }
        }
        $maxSize = trim((string) ($definition['maxSize'] ?? ''));
        if ($maxSize !== '') {
            \GroceryRestable\Support\Size::toBytes($maxSize);
        }

        return [
            'public' => (bool) ($definition['public'] ?? false),
            'allowedTypes' => trim((string) ($definition['allowedTypes'] ?? '')) ?: null,
            'maxSize' => $maxSize ?: null,
            'permissions' => $permissions,
            'description' => (string) ($definition['description'] ?? ''),
        ];
    }
}
