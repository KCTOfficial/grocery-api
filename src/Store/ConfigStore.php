<?php

declare(strict_types=1);

namespace GroceryRestable\Store;

use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Support\Crypto;
use PDO;

/**
 * SQLite backed store for everything configured from the admin GUI:
 * settings, database connections, resources, API keys, storage buckets,
 * admin users, login throttling and the schema cache.
 */
final class ConfigStore
{
    private const SCHEMA_VERSION = 3;

    /**
     * Runtime settings and their defaults.
     */
    public const SETTING_DEFAULTS = [
        'setup_completed' => false,
        'framework' => null,
        'app_name' => 'Grocery Restable',
        'api_description' => '',
        'default_connection' => 'default',
        // Expose every table of the default connection that is not configured explicitly.
        'auto_expose' => false,
        'auto_exclude_tables' => ['migrations'],
        // none | api_key | jwt | any
        'auth_mode' => 'api_key',
        // Permissions used by resources that do not define their own.
        'default_permissions' => [
            'anon' => [],
            'authenticated' => ['select', 'insert', 'update', 'delete'],
        ],
        'jwt_secret' => '',
        'jwt_algorithm' => 'HS256',
        'jwt_role_claim' => 'role',
        'jwt_default_role' => 'authenticated',
        'jwt_audience' => '',
        'jwt_issuer' => '',
        // Reject tokens without "exp" (they would never expire).
        'jwt_require_exp' => true,
        // service_role bypasses every permission; by default only its API key grants it.
        'jwt_allow_service_role' => false,
        // Accept ?apikey=... (handy for <img src>, but keys end up in access logs).
        'allow_query_apikey' => false,
        'cors_enabled' => true,
        'cors_origins' => ['*'],
        'cors_max_age' => 600,
        'default_per_page' => 25,
        'max_limit' => 1000,
        // Wrap list responses in {"data": [...], "meta": {...}} instead of a bare array.
        'response_envelope' => false,
        'schema_cache_ttl' => 300,
        'upload_allowed_types' => 'gif|jpeg|jpg|png|webp|tiff|doc|docx|txt|odt|xls|xlsx|pdf|ppt|pptx|csv|zip|mp3|m4a|ogg|wav|mp4|m4v|mov|webm',
        'upload_max_size' => '20MB',
        'delete_replaced_files' => true,
        'expose_openapi' => true,
        // Rate limiting of the REST API (fixed window, per client).
        'rate_limit_enabled' => true,
        'rate_limit_requests' => 600,
        'rate_limit_window' => 60,
        // Anonymous callers (role anon); 0 = same limit as everybody.
        'rate_limit_anon_requests' => 120,
        'rate_limit_exempt_service' => true,
        // JSON logs in {logPath}/YYYY-MM-DD.jsonl. log_requests: all | errors | none.
        'log_requests' => 'all',
        'log_admin' => true,
        'log_retention_days' => 14,
        // Storage housekeeping: once a day remove temporary files, empty folders, stale index rows
        // and expired trash, and index files copied into buckets by hand. Orphans are never removed automatically.
        'storage_auto_cleanup' => true,
        // Files younger than this are never treated as orphans (uploads still in progress).
        'orphan_min_age_hours' => 24,
        // Cleaned files are moved to {storagePath}/trash and deleted after this many days (0 = delete immediately).
        'storage_trash_days' => 7,
    ];

    /**
     * Settings that are encrypted at rest and never returned to the GUI in clear text.
     */
    public const SECRET_SETTINGS = ['jwt_secret'];

    private ?PDO $pdo = null;

    /** @var array<string, mixed>|null */
    private ?array $settingsCache = null;

    public function __construct(
        private readonly string $path,
        private readonly Crypto $crypto,
    ) {
    }

    public function crypto(): Crypto
    {
        return $this->crypto;
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if ($this->path !== ':memory:') {
            $dir = dirname($this->path);
            if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new ConfigurationException('Unable to create the Grocery Restable storage directory: ' . $dir);
            }
        }

        $isNew = $this->path === ':memory:' || ! is_file($this->path);
        $this->pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        if ($isNew && $this->path !== ':memory:') {
            @chmod($this->path, 0600);
        }
        $this->migrate();

        return $this->pdo;
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        if ($this->settingsCache === null) {
            $values = self::SETTING_DEFAULTS;
            foreach ($this->pdo()->query('SELECT key, value FROM settings') as $row) {
                $value = json_decode($row['value'], true);
                if (in_array($row['key'], self::SECRET_SETTINGS, true) && is_string($value)) {
                    $value = $this->crypto->decrypt($value);
                }
                $values[$row['key']] = $value;
            }
            $this->settingsCache = $values;
        }

        return $this->settingsCache;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings()[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function saveSettings(array $values): void
    {
        $statement = $this->pdo()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::SETTING_DEFAULTS)) {
                throw new ConfigurationException('Unknown setting "' . $key . '".');
            }
            if (in_array($key, self::SECRET_SETTINGS, true) && is_string($value)) {
                $value = $this->crypto->encrypt($value);
            }
            $statement->execute([$key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        }
        $this->settingsCache = null;
    }

    public function isSetupCompleted(): bool
    {
        return (bool) $this->setting('setup_completed', false);
    }

    // ------------------------------------------------------------------
    // Database connections
    // ------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>> Connection definitions keyed by name (passwords decrypted).
     */
    public function connections(): array
    {
        $result = [];
        foreach ($this->pdo()->query('SELECT name, config FROM connections ORDER BY name') as $row) {
            $config = json_decode($row['config'], true) ?: [];
            $config['password'] = $this->crypto->decrypt($config['password'] ?? null);
            $result[$row['name']] = $config + ['name' => $row['name']];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function connection(string $name): ?array
    {
        return $this->connections()[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function saveConnection(string $name, array $config): void
    {
        self::assertName($name, 'connection');
        unset($config['name']);
        $config['password'] = $this->crypto->encrypt(isset($config['password']) ? (string) $config['password'] : null);

        $now = gmdate('c');
        $this->pdo()->prepare(
            'INSERT INTO connections (name, config, created_at, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(name) DO UPDATE SET config = excluded.config, updated_at = excluded.updated_at',
        )->execute([$name, json_encode($config, JSON_THROW_ON_ERROR), $now, $now]);
        $this->clearCache('schema:' . $name);
    }

    public function deleteConnection(string $name): void
    {
        $this->pdo()->prepare('DELETE FROM connections WHERE name = ?')->execute([$name]);
        $this->clearCache('schema:' . $name);
    }

    // ------------------------------------------------------------------
    // Resources (GUI defined)
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{definition: array<string, mixed>, enabled: bool, updated_at: string|null}>
     */
    public function resources(): array
    {
        $result = [];
        foreach ($this->pdo()->query('SELECT name, definition, enabled, updated_at FROM resources ORDER BY name') as $row) {
            $result[$row['name']] = [
                'definition' => json_decode($row['definition'], true) ?: [],
                'enabled' => (bool) $row['enabled'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function saveResource(string $name, array $definition, bool $enabled = true): void
    {
        self::assertName($name, 'resource');
        $now = gmdate('c');
        $this->pdo()->prepare(
            'INSERT INTO resources (name, definition, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(name) DO UPDATE SET definition = excluded.definition, enabled = excluded.enabled, updated_at = excluded.updated_at',
        )->execute([$name, json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (int) $enabled, $now, $now]);
    }

    public function deleteResource(string $name): void
    {
        $this->pdo()->prepare('DELETE FROM resources WHERE name = ?')->execute([$name]);
    }

    // ------------------------------------------------------------------
    // API keys
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function apiKeys(): array
    {
        return $this->pdo()->query('SELECT id, name, prefix, role, created_at, last_used_at, revoked_at FROM api_keys ORDER BY id')->fetchAll();
    }

    /**
     * Create a key and return the plain value. Only its SHA-256 hash is stored.
     */
    public function createApiKey(string $name, string $role): string
    {
        self::assertRole($role);
        $plain = 'gr_' . bin2hex(random_bytes(24));
        $this->pdo()->prepare('INSERT INTO api_keys (name, prefix, key_hash, role, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([trim($name) !== '' ? trim($name) : 'API key', substr($plain, 0, 11), hash('sha256', $plain), $role, gmdate('c')]);

        return $plain;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findApiKey(string $plain): ?array
    {
        $statement = $this->pdo()->prepare('SELECT id, name, role, last_used_at FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL');
        $statement->execute([hash('sha256', $plain)]);
        $row = $statement->fetch();
        if (! $row) {
            return null;
        }

        // Avoid a write on every request: refresh "last used" at most once a minute.
        if ($row['last_used_at'] === null || strtotime($row['last_used_at']) < time() - 60) {
            $this->pdo()->prepare('UPDATE api_keys SET last_used_at = ? WHERE id = ?')->execute([gmdate('c'), $row['id']]);
        }

        return $row;
    }

    public function revokeApiKey(int $id): void
    {
        $this->pdo()->prepare('UPDATE api_keys SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')->execute([gmdate('c'), $id]);
    }

    public function deleteApiKey(int $id): void
    {
        $this->pdo()->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$id]);
    }

    // ------------------------------------------------------------------
    // Storage buckets
    // ------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buckets(): array
    {
        $result = [];
        foreach ($this->pdo()->query('SELECT name, definition FROM buckets ORDER BY name') as $row) {
            $result[$row['name']] = (json_decode($row['definition'], true) ?: []) + ['name' => $row['name']];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function saveBucket(string $name, array $definition): void
    {
        self::assertName($name, 'bucket');
        unset($definition['name']);
        $now = gmdate('c');
        $this->pdo()->prepare(
            'INSERT INTO buckets (name, definition, created_at, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(name) DO UPDATE SET definition = excluded.definition, updated_at = excluded.updated_at',
        )->execute([$name, json_encode($definition, JSON_THROW_ON_ERROR), $now, $now]);
    }

    public function deleteBucket(string $name): void
    {
        $this->pdo()->prepare('DELETE FROM buckets WHERE name = ?')->execute([$name]);
    }

    // ------------------------------------------------------------------
    // Admin users
    // ------------------------------------------------------------------

    public function countUsers(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function users(): array
    {
        return $this->pdo()->query('SELECT id, username, created_at, last_login_at FROM admin_users ORDER BY username')->fetchAll();
    }

    public function createUser(string $username, string $password): int
    {
        $username = trim($username);
        self::assertUsername($username);
        self::assertPassword($password);

        $this->pdo()->prepare('INSERT INTO admin_users (username, password_hash, created_at) VALUES (?, ?, ?)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), gmdate('c')]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Verify credentials. Returns the user row on success.
     *
     * @return array<string, mixed>|null
     */
    public function verifyUser(string $username, string $password): ?array
    {
        $statement = $this->pdo()->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = ?');
        $statement->execute([trim($username)]);
        $user = $statement->fetch();

        if (! $user) {
            // Equalize timing between unknown users and wrong passwords.
            password_verify($password, password_hash('grocery-restable-timing-guard', PASSWORD_DEFAULT));

            return null;
        }
        if (! password_verify($password, $user['password_hash'])) {
            return null;
        }

        $updates = ['last_login_at = ?' => gmdate('c')];
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $updates['password_hash = ?'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $this->pdo()->prepare('UPDATE admin_users SET ' . implode(', ', array_keys($updates)) . ' WHERE id = ?')
            ->execute([...array_values($updates), $user['id']]);

        unset($user['password_hash']);

        return $user;
    }

    public function changePassword(int $id, string $password): void
    {
        self::assertPassword($password);
        $this->pdo()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public function changeUsername(int $id, string $username): void
    {
        $username = trim($username);
        self::assertUsername($username);
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM admin_users WHERE username = ? AND id <> ?');
        $statement->execute([$username, $id]);
        if ((int) $statement->fetchColumn() > 0) {
            throw new ConfigurationException('The username "' . $username . '" is already taken.');
        }
        $this->pdo()->prepare('UPDATE admin_users SET username = ? WHERE id = ?')->execute([$username, $id]);
    }

    /**
     * Check the password of an admin user (without touching last_login_at).
     */
    public function checkPassword(int $id, string $password): bool
    {
        $statement = $this->pdo()->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
        $statement->execute([$id]);
        $hash = $statement->fetchColumn();

        return is_string($hash) && password_verify($password, $hash);
    }

    public function deleteUser(int $id): void
    {
        if ($this->countUsers() <= 1) {
            throw new ConfigurationException('The last admin user cannot be deleted.');
        }
        $this->pdo()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
    }

    // ------------------------------------------------------------------
    // Login throttling
    // ------------------------------------------------------------------

    public function recordFailedLogin(string $ip, string $username): void
    {
        $this->pdo()->prepare('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, ?)')
            ->execute([$ip, mb_substr($username, 0, 64), time()]);
        $this->pdo()->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([time() - 86400]);
    }

    public function recentFailedLogins(string $ip, int $windowSeconds): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?');
        $statement->execute([$ip, time() - $windowSeconds]);

        return (int) $statement->fetchColumn();
    }

    public function clearFailedLogins(string $ip): void
    {
        $this->pdo()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
    }

    // ------------------------------------------------------------------
    // Storage object index (S3 style metadata of bucket files)
    // ------------------------------------------------------------------

    /**
     * Insert or replace the metadata of an object (created_at is kept on replace).
     *
     * @param array{size: int, etag: string, content_type: string, original_name?: string|null, cache_control?: string|null,
     *              metadata?: array<string, string>|null, owner?: string|null, created_at?: string, updated_at?: string} $object
     */
    public function putObject(string $bucket, string $path, array $object): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->pdo()->prepare(
            'INSERT INTO storage_objects (bucket, path, size, etag, content_type, original_name, cache_control, metadata, owner, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(bucket, path) DO UPDATE SET size = excluded.size, etag = excluded.etag, content_type = excluded.content_type,
                original_name = excluded.original_name, cache_control = excluded.cache_control, metadata = excluded.metadata,
                owner = excluded.owner, updated_at = excluded.updated_at',
        )->execute([
            $bucket, $path, $object['size'], $object['etag'], $object['content_type'], $object['original_name'] ?? null,
            $object['cache_control'] ?? null, isset($object['metadata']) && $object['metadata'] !== [] ? json_encode($object['metadata'], JSON_UNESCAPED_UNICODE) : null,
            $object['owner'] ?? null, $object['created_at'] ?? $now, $object['updated_at'] ?? $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function object(string $bucket, string $path): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM storage_objects WHERE bucket = ? AND path = ?');
        $statement->execute([$bucket, $path]);
        $row = $statement->fetch();

        return $row ? self::objectRow($row) : null;
    }

    public function deleteObject(string $bucket, string $path): void
    {
        $this->pdo()->prepare('DELETE FROM storage_objects WHERE bucket = ? AND path = ?')->execute([$bucket, $path]);
    }

    /**
     * Objects of a bucket in key order (binary, like S3), with a key prefix, after a key.
     *
     * @return list<array<string, mixed>>
     */
    public function objects(string $bucket, string $prefix = '', string $after = '', int $limit = 1000): array
    {
        $sql = 'SELECT * FROM storage_objects WHERE bucket = ? AND path > ?';
        $bindings = [$bucket, $after];
        if ($prefix !== '') {
            // A range instead of LIKE: no escaping of % and _, and the primary key index is used.
            $sql .= ' AND path >= ? AND path < ?';
            array_push($bindings, $prefix, $prefix . "\u{10FFFF}");
        }
        $statement = $this->pdo()->prepare($sql . ' ORDER BY path LIMIT ' . max(1, $limit));
        $statement->execute($bindings);

        return array_map([self::class, 'objectRow'], $statement->fetchAll());
    }

    public function countObjects(string $bucket): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM storage_objects WHERE bucket = ?');
        $statement->execute([$bucket]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<string> Buckets that have index rows.
     */
    public function indexedBuckets(): array
    {
        return $this->pdo()->query('SELECT DISTINCT bucket FROM storage_objects ORDER BY bucket')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function deleteBucketObjects(string $bucket): int
    {
        $statement = $this->pdo()->prepare('DELETE FROM storage_objects WHERE bucket = ?');
        $statement->execute([$bucket]);

        return $statement->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function objectRow(array $row): array
    {
        $row['size'] = (int) $row['size'];
        $row['metadata'] = $row['metadata'] !== null ? (json_decode((string) $row['metadata'], true) ?: []) : [];

        return $row;
    }

    // ------------------------------------------------------------------
    // API rate limiting (fallback when APCu is not available)
    // ------------------------------------------------------------------

    /**
     * Count a hit for $key in the window starting at $windowStart; returns the hits of that window.
     */
    public function hitRateLimit(string $key, int $windowStart): int
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO rate_limits (key, window_start, hits) VALUES (?, ?, 1)
                 ON CONFLICT(key) DO UPDATE SET
                    hits = CASE WHEN rate_limits.window_start = excluded.window_start THEN rate_limits.hits + 1 ELSE 1 END,
                    window_start = excluded.window_start',
            )->execute([$key, $windowStart]);
            $statement = $pdo->prepare('SELECT hits FROM rate_limits WHERE key = ?');
            $statement->execute([$key]);
            $hits = (int) $statement->fetchColumn();
            if (random_int(1, 200) === 1) {
                $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([time() - 86400]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $hits;
    }

    // ------------------------------------------------------------------
    // Cache
    // ------------------------------------------------------------------

    public function cacheGet(string $key): mixed
    {
        $statement = $this->pdo()->prepare('SELECT value FROM cache WHERE key = ? AND expires_at > ?');
        $statement->execute([$key, time()]);
        $value = $statement->fetchColumn();

        return $value === false ? null : json_decode($value, true);
    }

    public function cachePut(string $key, mixed $value, int $ttl): void
    {
        if ($ttl <= 0) {
            return;
        }
        $this->pdo()->prepare(
            'INSERT INTO cache (key, value, expires_at) VALUES (?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at',
        )->execute([$key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), time() + $ttl]);
    }

    public function clearCache(?string $key = null): void
    {
        if ($key === null) {
            $this->pdo()->exec('DELETE FROM cache');

            return;
        }
        $this->pdo()->prepare('DELETE FROM cache WHERE key = ?')->execute([$key]);
    }

    // ------------------------------------------------------------------
    // Import / export
    // ------------------------------------------------------------------

    /**
     * Export the GUI configuration (without users, keys and secrets) as an array.
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $settings = $this->settings();
        foreach (self::SECRET_SETTINGS as $secret) {
            unset($settings[$secret]);
        }
        $connections = $this->connections();
        foreach ($connections as &$connection) {
            unset($connection['password']);
        }

        return [
            'version' => self::SCHEMA_VERSION,
            'exported_at' => gmdate('c'),
            'settings' => $settings,
            'connections' => $connections,
            'resources' => $this->resources(),
            'buckets' => $this->buckets(),
        ];
    }

    // ------------------------------------------------------------------

    private function migrate(): void
    {
        $version = (int) $this->pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version >= self::SCHEMA_VERSION) {
            return;
        }

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS connections (name TEXT PRIMARY KEY, config TEXT NOT NULL, created_at TEXT, updated_at TEXT);
            CREATE TABLE IF NOT EXISTS resources (name TEXT PRIMARY KEY, definition TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT, updated_at TEXT);
            CREATE TABLE IF NOT EXISTS api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, prefix TEXT NOT NULL, key_hash TEXT NOT NULL UNIQUE,
                role TEXT NOT NULL, created_at TEXT, last_used_at TEXT, revoked_at TEXT
            );
            CREATE TABLE IF NOT EXISTS buckets (name TEXT PRIMARY KEY, definition TEXT NOT NULL, created_at TEXT, updated_at TEXT);
            CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL, created_at TEXT, last_login_at TEXT
            );
            CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, username TEXT, attempted_at INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS login_attempts_ip ON login_attempts (ip, attempted_at);
            CREATE TABLE IF NOT EXISTS cache (key TEXT PRIMARY KEY, value TEXT NOT NULL, expires_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS rate_limits (key TEXT PRIMARY KEY, window_start INTEGER NOT NULL, hits INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS storage_objects (
                bucket TEXT NOT NULL, path TEXT NOT NULL, size INTEGER NOT NULL, etag TEXT NOT NULL, content_type TEXT NOT NULL,
                original_name TEXT, cache_control TEXT, metadata TEXT, owner TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                PRIMARY KEY (bucket, path)
            );
            SQL);
        $this->pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    public static function assertName(string $name, string $what): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $name)) {
            throw new ConfigurationException(sprintf('Invalid %s name "%s". Use letters, digits, "_" or "-" (max 64, must not start with a digit).', $what, $name));
        }
    }

    public static function assertRole(string $role): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $role)) {
            throw new ConfigurationException('Invalid role name "' . $role . '".');
        }
    }

    private static function assertUsername(string $username): void
    {
        if (! preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $username)) {
            throw new ConfigurationException('Usernames must be 3-64 characters (letters, digits, . _ @ -).');
        }
    }

    private static function assertPassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new ConfigurationException('Passwords must be at least 8 characters long.');
        }
    }
}
