<?php

declare(strict_types=1);

namespace GroceryRestable\Service;

use DateTimeImmutable;
use GroceryRestable\Database\Grammar\Grammar;
use GroceryRestable\GroceryRestable;
use GroceryRestable\Resource\UploadField;
use Throwable;

/**
 * Finds and cleans files nobody uses any more.
 *
 * Tasks (scan() reports them, clean() runs the selected ones):
 *   orphan_files       field upload files no row references any more        -> moved to the trash
 *   orphan_buckets     folders of buckets that no longer exist                -> moved to the trash
 *   stale_index        metadata rows whose file is gone, or of deleted buckets -> removed
 *   untracked_objects  bucket files without metadata (copied in by hand)      -> indexed
 *   tmp_files          interrupted uploads older than one hour                -> deleted
 *   empty_folders      empty folders inside buckets (S3 has no folders)       -> removed
 *   trash              trash days older than storage_trash_days               -> deleted
 *   trash_all          everything in the trash                                -> deleted
 *
 * Safety: files younger than orphan_min_age_hours are never orphans; an upload folder is skipped when one
 * of the resources using it cannot be checked (unknown table or column, custom upload callback); orphans
 * go to {storagePath}/trash/{day}/ first and are only deleted with the trash.
 */
final class StorageJanitor
{
    public const TASKS = ['orphan_files', 'orphan_buckets', 'stale_index', 'untracked_objects', 'tmp_files', 'empty_folders', 'trash', 'trash_all'];
    /** Tasks of the automatic daily run: they never delete data that could still be in use. */
    public const ROUTINE = ['stale_index', 'untracked_objects', 'tmp_files', 'empty_folders', 'trash'];
    private const TMP_MAX_AGE = 3600;
    private const LIST_LIMIT = 200;
    private const BATCH = 500;

    public function __construct(private readonly GroceryRestable $api)
    {
    }

    // ------------------------------------------------------------------
    // Scan & clean
    // ------------------------------------------------------------------

    /**
     * Dry run: what clean() would do, per task (count, bytes, first items) plus skipped upload folders.
     *
     * @return array<string, mixed>
     */
    public function scan(?int $minAgeHours = null): array
    {
        $minAge = $this->minAge($minAgeHours);
        [$orphans, $skipped] = $this->orphanFiles($minAge);
        $tasks = [
            'orphan_files' => $this->summary($orphans, static fn (array $o): array => ['directory' => $o['label'], 'file' => $o['file'], 'size' => $o['size'], 'modified' => $o['modified']]),
            'orphan_buckets' => $this->summary($this->orphanBuckets(), static fn (array $b): array => ['bucket' => $b['bucket'], 'files' => $b['files'], 'size' => $b['size']]),
            'stale_index' => $this->summary($this->staleIndex(), static fn (array $o): array => ['bucket' => $o['bucket'], 'path' => $o['path'], 'size' => $o['size']]),
            'untracked_objects' => $this->summary($this->untrackedObjects(), static fn (array $o): array => ['bucket' => $o['bucket'], 'path' => $o['path'], 'size' => $o['size']]),
            'tmp_files' => $this->summary($this->tmpFiles(), static fn (array $f): array => ['file' => basename($f['path']), 'size' => $f['size']]),
            'empty_folders' => $this->summary($this->emptyFolders(), static fn (array $f): array => ['bucket' => $f['bucket'], 'path' => $f['relative'], 'size' => 0]),
            'trash' => $this->summary($this->trashDays(true), static fn (array $d): array => ['day' => $d['day'], 'files' => $d['files'], 'size' => $d['size']]),
            'trash_all' => $this->summary($this->trashDays(false), static fn (array $d): array => ['day' => $d['day'], 'files' => $d['files'], 'size' => $d['size']]),
        ];

        return [
            'scanned_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'min_age_hours' => intdiv($minAge, 3600),
            'trash_days' => (int) $this->api->store()->setting('storage_trash_days', 7),
            'trash_path' => $this->trashRoot(),
            'tasks' => $tasks,
            'skipped' => $skipped,
        ];
    }

    /**
     * Run tasks. Returns the number of handled files / rows / folders per task.
     *
     * @param list<string> $tasks
     * @return array<string, int>
     */
    public function clean(array $tasks, ?int $minAgeHours = null): array
    {
        $tasks = array_values(array_intersect(self::TASKS, $tasks));
        $minAge = $this->minAge($minAgeHours);
        $storage = $this->api->storage();
        $store = $this->api->store();
        $done = array_fill_keys($tasks, 0);

        foreach ($tasks as $task) {
            switch ($task) {
                case 'orphan_files':
                    foreach ($this->orphanFiles($minAge)[0] as $orphan) {
                        $done[$task] += (int) $this->toTrash($orphan['path'], 'uploads/' . $orphan['trash']);
                    }
                    break;
                case 'orphan_buckets':
                    foreach ($this->orphanBuckets() as $bucket) {
                        $done[$task] += (int) $this->toTrash($bucket['path'], 'buckets/' . $bucket['bucket']);
                        $store->deleteBucketObjects($bucket['bucket']);
                    }
                    break;
                case 'untracked_objects':
                    foreach (array_keys($storage->buckets()) as $bucket) {
                        $done[$task] += $storage->reindex($bucket)['indexed'];
                    }
                    break;
                case 'stale_index':
                    foreach ($this->staleIndex() as $row) {
                        $store->deleteObject($row['bucket'], $row['path']);
                        $done[$task]++;
                    }
                    break;
                case 'tmp_files':
                    foreach ($this->tmpFiles() as $file) {
                        $done[$task] += (int) @unlink($file['path']);
                    }
                    break;
                case 'empty_folders':
                    foreach ($this->emptyFolders() as $folder) {
                        $done[$task] += (int) @rmdir($folder['path']);
                    }
                    break;
                case 'trash':
                case 'trash_all':
                    foreach ($this->trashDays($task === 'trash') as $day) {
                        self::removeTree($day['path']);
                        $done[$task]++;
                    }
                    break;
            }
        }

        return $done;
    }

    /**
     * The safe housekeeping tasks, at most once per day (setting storage_auto_cleanup). Never throws.
     *
     * @return array<string, int>|null null when it already ran today or is disabled
     */
    public function routine(): ?array
    {
        $store = $this->api->store();
        $today = date('Y-m-d');
        try {
            if (! $store->setting('storage_auto_cleanup', true) || $store->cacheGet('storage_cleanup_day') === $today) {
                return null;
            }
            $store->cachePut('storage_cleanup_day', $today, 86400 * 2);

            return $this->clean(self::ROUTINE);
        } catch (Throwable $e) {
            $this->api->log('warning', 'Grocery Restable storage cleanup: ' . $e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    /**
     * Move the folder of a (deleted) bucket to the trash and forget its metadata.
     */
    public function trashBucket(string $bucket): bool
    {
        $directory = $this->api->config()->bucketPath() . DIRECTORY_SEPARATOR . $bucket;
        $this->api->store()->deleteBucketObjects($bucket);

        return preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $bucket) === 1 && is_dir($directory)
            && $this->toTrash($directory, 'buckets/' . $bucket);
    }

    /**
     * Delete the upload files of a row that was deleted (or whose file columns changed) outside the API,
     * e.g. in the admin data browser, unless another row still references them.
     *
     * @param array<string, mixed> $before Row before the change
     * @param array<string, mixed>|null $after Changed values, null when the row was deleted
     * @return int Deleted files
     */
    public function releaseRowFiles(string $connection, string $table, array $before, ?array $after = null): int
    {
        if (! $this->api->store()->setting('delete_replaced_files', true)) {
            return 0;
        }
        $deleted = 0;
        foreach ($this->uploadDirectories() as $directory) {
            if ($directory['error'] !== null) {
                continue;
            }
            foreach ($directory['refs'] as $ref) {
                if ($ref['connection'] !== $connection || $ref['table'] !== $table) {
                    continue;
                }
                $old = $before[$ref['column']] ?? null;
                if (! is_string($old) || $old === '' || ($after !== null && (! array_key_exists($ref['column'], $after) || $after[$ref['column']] === $old))) {
                    continue;
                }
                if ($this->referenced($directory['refs'], [$old]) === []) {
                    $path = $this->api->uploads()->fieldFilePath($ref['field'], $old);
                    if ($path !== null && @unlink($path)) {
                        $deleted++;
                    }
                }
            }
        }

        return $deleted;
    }

    // ------------------------------------------------------------------
    // Finders
    // ------------------------------------------------------------------

    /**
     * Upload folders of every resource (code and GUI, enabled or not), with the columns referencing files in them.
     *
     * @return array<string, array{path: string, label: string, refs: list<array{connection: string, table: string, column: string, field: UploadField}>, error: string|null}>
     */
    public function uploadDirectories(): array
    {
        $api = $this->api;
        $default = $api->connections()->defaultName();
        $owners = [];
        foreach ($api->registry()->codeResources() as $resource) {
            $owners[] = [$resource->getName(), $resource->getConnection() ?? $default, $resource->getTable(), $resource->getUploads(), $resource->getCallbacks('upload') !== []];
        }
        foreach ($api->store()->resources() as $name => $row) {
            $definition = $row['definition'];
            $uploads = [];
            try {
                foreach ((array) ($definition['uploads'] ?? []) as $upload) {
                    $field = UploadField::fromArray((array) $upload);
                    $uploads[$field->field] = $field;
                }
            } catch (Throwable) {
                continue;
            }
            $owners[] = [$name, (string) ($definition['connection'] ?? $default), (string) ($definition['table'] ?? $name), $uploads, false];
        }

        $directories = [];
        foreach ($owners as [$name, $connection, $table, $uploads, $customUpload]) {
            /** @var UploadField $field */
            foreach ($uploads as $field) {
                $path = $api->uploads()->fieldDirectory($field, false);
                $key = strtolower(str_replace('\\', '/', $path));
                $directories[$key] ??= ['path' => $path, 'label' => $this->label($path), 'refs' => [], 'error' => null];
                $directories[$key]['refs'][] = ['connection' => $connection, 'table' => $table, 'column' => $field->field, 'field' => $field];
                $error = match (true) {
                    $customUpload => sprintf('%s.%s stores files with a custom upload callback', $name, $field->field),
                    ! $this->columnExists($connection, $table, $field->field) => sprintf('%s: column %s.%s not found', $name, $table, $field->field),
                    default => null,
                };
                $directories[$key]['error'] ??= $error;
            }
        }

        return $directories;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array{directory: string, reason: string}>}
     */
    private function orphanFiles(int $minAge): array
    {
        $orphans = [];
        $skipped = [];
        $now = time();
        foreach ($this->uploadDirectories() as $directory) {
            if (! is_dir($directory['path'])) {
                continue;
            }
            if ($directory['error'] !== null) {
                $skipped[] = ['directory' => $directory['label'], 'reason' => $directory['error']];
                continue;
            }
            $candidates = [];
            foreach (new \DirectoryIterator($directory['path']) as $entry) {
                if ($entry->isFile() && ! str_starts_with($entry->getFilename(), '.') && $now - $entry->getMTime() >= $minAge) {
                    $candidates[$entry->getFilename()] = $entry->getPathname();
                }
            }
            foreach (array_chunk(array_keys($candidates), self::BATCH) as $chunk) {
                $used = array_flip($this->referenced($directory['refs'], $chunk));
                foreach ($chunk as $file) {
                    if (! isset($used[$file])) {
                        $path = $candidates[$file];
                        $orphans[] = [
                            'label' => $directory['label'], 'file' => $file, 'path' => $path, 'size' => (int) filesize($path),
                            'modified' => gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($path)), 'trash' => $this->trashName($directory['path'], $file),
                        ];
                    }
                }
            }
        }

        return [$orphans, $skipped];
    }

    /**
     * Values among $files referenced by any of the columns.
     *
     * @param list<array{connection: string, table: string, column: string, field: UploadField}> $refs
     * @param list<string> $files
     * @return list<string>
     */
    private function referenced(array $refs, array $files): array
    {
        $found = [];
        foreach ($refs as $ref) {
            $db = $this->api->connections()->get($ref['connection']);
            $grammar = Grammar::for($db->driver());
            $column = $grammar->wrap($ref['column']);
            $rows = $db->select(
                'SELECT DISTINCT ' . $column . ' AS v FROM ' . $grammar->wrapTable($ref['table']) . ' WHERE ' . $column . ' IN (' . implode(', ', array_fill(0, count($files), '?')) . ')',
                $files,
            );
            foreach ($rows as $row) {
                $found[(string) $row['v']] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * @return list<array{bucket: string, path: string, files: int, size: int}>
     */
    private function orphanBuckets(): array
    {
        $root = $this->api->config()->bucketPath();
        if (! is_dir($root)) {
            return [];
        }
        $defined = $this->api->storage()->buckets();
        $result = [];
        foreach (new \DirectoryIterator($root) as $entry) {
            $name = $entry->getFilename();
            if ($entry->isDot() || ! $entry->isDir() || str_starts_with($name, '.') || isset($defined[$name])) {
                continue;
            }
            [$files, $size] = self::treeSize($entry->getPathname());
            $result[] = ['bucket' => $name, 'path' => $entry->getPathname(), 'files' => $files, 'size' => $size];
        }

        return $result;
    }

    /**
     * @return list<array{bucket: string, path: string, size: int}>
     */
    private function untrackedObjects(): array
    {
        $storage = $this->api->storage();
        $store = $this->api->store();
        $result = [];
        foreach (array_keys($storage->buckets()) as $bucket) {
            foreach ($storage->files($this->api->config()->bucketPath() . DIRECTORY_SEPARATOR . $bucket) as $key => $absolute) {
                if ($store->object($bucket, $key) === null) {
                    $result[] = ['bucket' => $bucket, 'path' => $key, 'size' => (int) filesize($absolute)];
                }
            }
        }

        return $result;
    }

    /**
     * @return list<array{bucket: string, path: string, size: int}>
     */
    private function staleIndex(): array
    {
        $store = $this->api->store();
        $defined = $this->api->storage()->buckets();
        $root = $this->api->config()->bucketPath();
        $result = [];
        foreach ($store->indexedBuckets() as $bucket) {
            $after = '';
            while (($rows = $store->objects($bucket, '', $after, self::BATCH)) !== []) {
                foreach ($rows as $row) {
                    $after = $row['path'];
                    $file = $root . DIRECTORY_SEPARATOR . $bucket . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['path']);
                    if (! isset($defined[$bucket]) || ! is_file($file)) {
                        $result[] = ['bucket' => $bucket, 'path' => $row['path'], 'size' => (int) $row['size']];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @return list<array{path: string, size: int}>
     */
    private function tmpFiles(): array
    {
        $directory = $this->api->config()->bucketPath() . DIRECTORY_SEPARATOR . StorageService::TMP_DIR;
        $result = [];
        if (is_dir($directory)) {
            foreach (new \DirectoryIterator($directory) as $entry) {
                if ($entry->isFile() && time() - $entry->getMTime() > self::TMP_MAX_AGE) {
                    $result[] = ['path' => $entry->getPathname(), 'size' => $entry->getSize()];
                }
            }
        }

        return $result;
    }

    /**
     * Empty folders inside bucket directories, deepest first (so a parent becomes empty after its children).
     *
     * @return list<array{bucket: string, path: string, relative: string}>
     */
    private function emptyFolders(): array
    {
        $result = [];
        foreach (array_keys($this->api->storage()->buckets()) as $bucket) {
            $root = $this->api->config()->bucketPath() . DIRECTORY_SEPARATOR . $bucket;
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            $empty = [];
            foreach ($iterator as $entry) {
                if (! $entry->isDir()) {
                    continue;
                }
                $children = array_diff(scandir($entry->getPathname()) ?: [], ['.', '..']);
                $onlyEmpty = array_filter($children, static fn (string $c): bool => ! isset($empty[$entry->getPathname() . DIRECTORY_SEPARATOR . $c]));
                if ($onlyEmpty === []) {
                    $empty[$entry->getPathname()] = true;
                    $result[] = ['bucket' => $bucket, 'path' => $entry->getPathname(), 'relative' => str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1)) . '/'];
                }
            }
        }

        return $result;
    }

    /**
     * Days in the trash; with $expiredOnly those older than storage_trash_days.
     *
     * @return list<array{day: string, path: string, files: int, size: int}>
     */
    private function trashDays(bool $expiredOnly): array
    {
        $root = $this->trashRoot();
        if (! is_dir($root)) {
            return [];
        }
        $keep = max(0, (int) $this->api->store()->setting('storage_trash_days', 7));
        $limit = (new DateTimeImmutable('today'))->modify('-' . $keep . ' days')->format('Y-m-d');
        $result = [];
        foreach (new \DirectoryIterator($root) as $entry) {
            $day = $entry->getFilename();
            if ($entry->isDot() || ! $entry->isDir() || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || ($expiredOnly && $day > $limit)) {
                continue;
            }
            [$files, $size] = self::treeSize($entry->getPathname());
            $result[] = ['day' => $day, 'path' => $entry->getPathname(), 'files' => $files, 'size' => $size];
        }
        usort($result, static fn (array $a, array $b): int => strcmp($a['day'], $b['day']));

        return $result;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function trashRoot(): string
    {
        return $this->api->config()->storagePath() . DIRECTORY_SEPARATOR . 'trash';
    }

    /**
     * Move a file or folder into today's trash (copy + delete across file systems). Returns success.
     */
    private function toTrash(string $source, string $relative): bool
    {
        if ((int) $this->api->store()->setting('storage_trash_days', 7) === 0) {
            return is_dir($source) ? self::removeTree($source) : @unlink($source);
        }
        $target = $this->trashRoot() . DIRECTORY_SEPARATOR . date('Y-m-d') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        while (file_exists($target)) {
            $target .= '~' . bin2hex(random_bytes(2));
        }
        $dir = dirname($target);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return false;
        }
        if (@rename($source, $target)) {
            return true;
        }
        if (is_file($source) && @copy($source, $target)) {
            return @unlink($source);
        }

        return false;
    }

    /**
     * Trash location of a field upload: relative to the upload path, or a hash for absolute folders elsewhere.
     */
    private function trashName(string $directory, string $file): string
    {
        $uploadPath = str_replace('\\', '/', $this->api->config()->uploadPath());
        $directory = str_replace('\\', '/', $directory);
        $relative = str_starts_with(strtolower($directory) . '/', strtolower($uploadPath) . '/')
            ? trim(substr($directory, strlen($uploadPath)), '/')
            : 'external/' . substr(sha1($directory), 0, 12);

        return ($relative !== '' ? $relative . '/' : '') . $file;
    }

    private function label(string $directory): string
    {
        $uploadPath = str_replace('\\', '/', $this->api->config()->uploadPath());
        $directory = str_replace('\\', '/', $directory);

        return str_starts_with($directory, $uploadPath) ? 'uploads/' . ltrim(substr($directory, strlen($uploadPath)), '/') : $directory;
    }

    private function columnExists(string $connection, string $table, string $column): bool
    {
        try {
            $found = $this->api->schema()->find($table, $connection);

            return $found !== null && $found->hasColumn($column);
        } catch (Throwable) {
            return false;
        }
    }

    private function minAge(?int $hours): int
    {
        return max(0, $hours ?? (int) $this->api->store()->setting('orphan_min_age_hours', 24)) * 3600;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param callable(array<string, mixed>): array<string, mixed> $map
     * @return array{count: int, bytes: int, items: list<array<string, mixed>>}
     */
    private function summary(array $items, callable $map): array
    {
        return [
            'count' => count($items),
            'bytes' => array_sum(array_map(static fn (array $i): int => (int) ($i['size'] ?? 0), $items)),
            'items' => array_map($map, array_slice($items, 0, self::LIST_LIMIT)),
        ];
    }

    /**
     * @return array{0: int, 1: int} files, bytes
     */
    private static function treeSize(string $directory): array
    {
        $files = 0;
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files++;
                $size += $file->getSize();
            }
        }

        return [$files, $size];
    }

    private static function removeTree(string $directory): bool
    {
        if (! is_dir($directory)) {
            return false;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        return @rmdir($directory);
    }
}
