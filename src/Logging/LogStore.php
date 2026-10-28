<?php

declare(strict_types=1);

namespace GroceryRestable\Logging;

use DateTimeImmutable;
use GroceryRestable\Exception\ApiException;
use GroceryRestable\Store\ConfigStore;
use SplFileObject;

/**
 * JSON logs, one file per day: {logPath}/2026-10-28.jsonl (one JSON object per line).
 *
 * Entries have a "type": "request" (REST API calls) or "admin" (GUI logins and configuration changes),
 * and a "level": info (< 400), warning (4xx, failed logins) or error (5xx).
 * Settings: log_requests (all | errors | none), log_admin (bool), log_retention_days (0 = keep forever).
 */
final class LogStore
{
    private const EXTENSION = '.jsonl';
    private const DAY_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';
    /** Query parameters whose value is never written to a log. */
    private const SECRET_PARAMS = ['apikey', 'api_key', 'token', 'access_token', 'password', 'secret'];

    public function __construct(
        private readonly string $directory,
        private readonly ConfigStore $store,
    ) {
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $entry
     */
    public function request(array $entry): void
    {
        $mode = (string) $this->store->setting('log_requests', 'all');
        $status = (int) ($entry['status'] ?? 0);
        if ($mode === 'none' || ($mode === 'errors' && $status < 400)) {
            return;
        }
        $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');
        $this->write(['type' => 'request', 'level' => $level] + $entry);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function admin(string $action, array $entry = [], string $level = 'info'): void
    {
        if (! $this->store->setting('log_admin', true)) {
            return;
        }
        $this->write(['type' => 'admin', 'level' => $level, 'action' => $action] + $entry);
    }

    /**
     * Remove secrets from a query string before it is logged.
     */
    public static function redactQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $parts = [];
        foreach (explode('&', $query) as $pair) {
            $name = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            $parts[] = in_array($name, self::SECRET_PARAMS, true) ? $name . '=***' : $pair;
        }

        return implode('&', $parts);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function write(array $entry): void
    {
        $now = new DateTimeImmutable();
        $file = $this->path($now->format('Y-m-d'));
        $isNew = ! is_file($file);
        if ($isNew && ! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            return; // logging must never break a request
        }

        $line = json_encode(['time' => $now->format('Y-m-d\TH:i:s.vP')] + $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($line === false || @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            return;
        }
        if ($isNew) {
            @chmod($file, 0600);
            $this->prune((int) $this->store->setting('log_retention_days', 14));
        }
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * Log files, newest first.
     *
     * @return list<array{day: string, size: int, modified: string}>
     */
    public function days(): array
    {
        $days = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*' . self::EXTENSION) ?: [] as $file) {
            $day = basename($file, self::EXTENSION);
            if (preg_match(self::DAY_PATTERN, $day)) {
                $days[] = ['day' => $day, 'size' => (int) filesize($file), 'modified' => date('c', (int) filemtime($file))];
            }
        }
        usort($days, static fn (array $a, array $b): int => strcmp($b['day'], $a['day']));

        return $days;
    }

    /**
     * Entries of a day (newest first) matching the filters, one page at a time, plus statistics of the matches.
     *
     * Filters: type (request | admin), level (info | warning | error), method, status (2xx | 3xx | 4xx | 5xx),
     * resource, search (case insensitive text anywhere in the entry).
     *
     * @param array<string, mixed> $filters
     * @return array{entries: list<array<string, mixed>>, total: int, page: int, perPage: int, stats: array<string, mixed>}
     */
    public function read(string $day, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $matches = [];
        foreach ($this->lines($day) as $line) {
            if (isset($filters['search']) && $filters['search'] !== '' && stripos($line, (string) $filters['search']) === false) {
                continue;
            }
            $entry = json_decode($line, true);
            if (is_array($entry) && $this->matches($entry, $filters)) {
                $matches[] = $entry;
            }
        }

        $stats = $this->stats($matches);
        $matches = array_reverse($matches);

        return [
            'entries' => array_slice($matches, ($page - 1) * $perPage, $perPage),
            'total' => count($matches),
            'page' => $page,
            'perPage' => $perPage,
            'stats' => $stats,
        ];
    }

    /**
     * Absolute path of the file of a day (the day is validated, so it can never leave the log directory).
     */
    public function path(string $day): string
    {
        if (! preg_match(self::DAY_PATTERN, $day)) {
            throw ApiException::badRequest('Invalid day "' . $day . '". Expected YYYY-MM-DD.');
        }

        return $this->directory . DIRECTORY_SEPARATOR . $day . self::EXTENSION;
    }

    // ------------------------------------------------------------------
    // Deleting
    // ------------------------------------------------------------------

    public function delete(string $day): bool
    {
        $file = $this->path($day);

        return is_file($file) && @unlink($file);
    }

    /**
     * Delete every log file, or only those older than $keepDays days (0 = all). Returns the number of files.
     */
    public function deleteOlderThan(int $keepDays = 0): int
    {
        $limit = $keepDays > 0 ? (new DateTimeImmutable('today'))->modify('-' . ($keepDays - 1) . ' days')->format('Y-m-d') : null;
        $deleted = 0;
        foreach ($this->days() as $entry) {
            if (($limit === null || $entry['day'] < $limit) && $this->delete($entry['day'])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Apply the retention setting (called when the first entry of a day is written).
     */
    public function prune(int $retentionDays): int
    {
        return $retentionDays > 0 ? $this->deleteOlderThan($retentionDays) : 0;
    }

    // ------------------------------------------------------------------

    /**
     * @return iterable<string>
     */
    private function lines(string $day): iterable
    {
        $file = $this->path($day);
        if (! is_file($file)) {
            return;
        }
        $handle = new SplFileObject($file, 'r');
        while (! $handle->eof()) {
            $line = trim((string) $handle->fgets());
            if ($line !== '') {
                yield $line;
            }
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $filters
     */
    private function matches(array $entry, array $filters): bool
    {
        foreach (['type', 'level', 'method', 'resource'] as $key) {
            if (($filters[$key] ?? '') !== '' && strcasecmp((string) ($entry[$key] ?? ''), (string) $filters[$key]) !== 0) {
                return false;
            }
        }
        $status = (string) ($filters['status'] ?? '');
        if (preg_match('/^[1-5]xx$/', $status) && intdiv((int) ($entry['status'] ?? 0), 100) !== (int) $status[0]) {
            return false;
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private function stats(array $entries): array
    {
        $levels = ['info' => 0, 'warning' => 0, 'error' => 0];
        $statuses = [];
        $hours = array_fill(0, 24, 0);
        $durations = [];
        $resources = [];
        $clients = [];
        foreach ($entries as $entry) {
            $levels[$entry['level'] ?? 'info'] = ($levels[$entry['level'] ?? 'info'] ?? 0) + 1;
            $hour = (int) substr((string) ($entry['time'] ?? ''), 11, 2);
            $hours[$hour]++;
            if (($entry['type'] ?? '') !== 'request') {
                continue;
            }
            $class = intdiv((int) ($entry['status'] ?? 0), 100) . 'xx';
            $statuses[$class] = ($statuses[$class] ?? 0) + 1;
            $durations[] = (float) ($entry['duration_ms'] ?? 0);
            if (isset($entry['resource'])) {
                $resources[$entry['resource']] = ($resources[$entry['resource']] ?? 0) + 1;
            }
            if (isset($entry['ip'])) {
                $clients[$entry['ip']] = ($clients[$entry['ip']] ?? 0) + 1;
            }
        }
        sort($durations);
        arsort($resources);
        arsort($clients);
        ksort($statuses);
        $count = count($durations);

        return [
            'total' => count($entries),
            'requests' => $count,
            'levels' => $levels,
            'statuses' => $statuses,
            'hours' => $hours,
            'avgMs' => $count > 0 ? round(array_sum($durations) / $count, 2) : 0,
            'p95Ms' => $count > 0 ? $durations[(int) min($count - 1, ceil($count * 0.95) - 1)] : 0,
            'topResources' => array_slice($resources, 0, 5, true),
            'topClients' => array_slice($clients, 0, 5, true),
        ];
    }
}
