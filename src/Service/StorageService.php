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
 * File storage buckets (Supabase Storage style).
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
    private const MAX_LIST = 1000;

    /** @var array<string, array<string, mixed>> */
    private array $codeBuckets = [];

    public function __construct(
        private readonly Config $config,
        private readonly ConfigStore $store,
        private readonly UploadService $uploads,
    ) {
    }

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

    /**
     * @return array{prefix: string, folders: list<string>, files: list<array<string, mixed>>}
     */
    public function listObjects(string $bucketName, string $prefix, AuthContext $auth): array
    {
        $bucket = $this->bucket($bucketName);
        $this->authorize($auth, $bucket, 'select');
        $prefix = trim($prefix, '/');
        $directory = $this->directory($bucketName) . ($prefix !== '' ? DIRECTORY_SEPARATOR . $this->safeRelative($prefix) : '');

        $folders = [];
        $files = [];
        if (is_dir($directory)) {
            foreach (new \DirectoryIterator($directory) as $entry) {
                if ($entry->isDot() || str_starts_with($entry->getFilename(), '.')) {
                    continue;
                }
                if ($entry->isDir()) {
                    $folders[] = $entry->getFilename();
                } elseif ($entry->isFile()) {
                    $relative = ltrim($prefix . '/' . $entry->getFilename(), '/');
                    $files[] = [
                        'name' => $entry->getFilename(),
                        'path' => $relative,
                        'size' => $entry->getSize(),
                        'mime' => $this->uploads->detectMime($entry->getPathname()),
                        'updated_at' => gmdate('c', $entry->getMTime()),
                    ];
                }
                if (count($folders) + count($files) >= self::MAX_LIST) {
                    break;
                }
            }
        }
        sort($folders, SORT_NATURAL | SORT_FLAG_CASE);
        usort($files, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return ['prefix' => $prefix, 'folders' => $folders, 'files' => $files];
    }

    /**
     * @return array<string, mixed>
     */
    public function upload(string $bucketName, UploadedFileInterface $file, string $folder, ?string $name, bool $upsert, AuthContext $auth): array
    {
        $bucket = $this->bucket($bucketName);
        $this->authorize($auth, $bucket, 'insert');

        $checked = $this->uploads->validate(
            $file,
            UploadService::parseTypes((string) ($bucket['allowedTypes'] ?? '')),
            isset($bucket['maxSize']) && $bucket['maxSize'] !== '' ? (string) $bucket['maxSize'] : null,
        );

        $folder = trim($folder, '/');
        $baseName = $name !== null && $name !== '' ? pathinfo($name, PATHINFO_FILENAME) : pathinfo($file->getClientFilename(), PATHINFO_FILENAME);
        $fileName = UploadService::sanitizeBaseName($baseName) . '.' . $checked['extension'];
        $relative = ltrim(($folder !== '' ? $this->safeRelative($folder) . '/' : '') . $fileName, '/');

        $target = $this->directory($bucketName) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($target)) {
            if (! $upsert) {
                throw ApiException::conflict('An object already exists at "' . $relative . '". Send upsert=true to replace it.');
            }
            $this->authorize($auth, $bucket, 'update');
        }
        $dir = dirname($target);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create the bucket folder.');
        }
        $size = $file->getSize();
        if (is_file($target)) {
            unlink($target);
        }
        $file->moveTo($target);

        return ['bucket' => $bucketName, 'path' => $relative, 'name' => $fileName, 'size' => $size, 'mime' => $checked['mime']];
    }

    /**
     * @return array{path: string, name: string, mime: string}
     */
    public function download(string $bucketName, string $path, AuthContext $auth): array
    {
        $bucket = $this->bucket($bucketName);
        $this->authorize($auth, $bucket, 'select');
        $file = $this->existingFile($bucketName, $path);

        return ['path' => $file, 'name' => basename($file), 'mime' => $this->uploads->detectMime($file)];
    }

    public function delete(string $bucketName, string $path, AuthContext $auth): void
    {
        $bucket = $this->bucket($bucketName);
        $this->authorize($auth, $bucket, 'delete');
        unlink($this->existingFile($bucketName, $path));
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

    private function existingFile(string $bucketName, string $path): string
    {
        $root = realpath($this->directory($bucketName));
        $candidate = realpath($this->directory($bucketName) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->safeRelative($path)));
        if ($root === false || $candidate === false || ! is_file($candidate) || ! str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)) {
            throw ApiException::notFound('Object not found.');
        }

        return $candidate;
    }

    /**
     * Validate a relative object path ("folder/sub/file.jpg").
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
