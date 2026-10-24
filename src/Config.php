<?php

declare(strict_types=1);

namespace GroceryRestable;

use GroceryRestable\Exception\ConfigurationException;

/**
 * Bootstrap configuration.
 *
 * Only values that must be known *before* the SQLite configuration store can be
 * opened (paths, secrets, URL prefixes) live here. Everything that is managed
 * from the admin GUI is stored in SQLite (see {@see Store\ConfigStore}).
 */
final class Config
{
    /**
     * Framework integrations: "standalone" (no framework, native PHP) and CodeIgniter 4.
     */
    public const FRAMEWORKS = [
        'standalone'   => 'No framework (native PHP)',
        'codeigniter4' => 'CodeIgniter 4',
    ];

    public const DEFAULTS = [
        // Which framework integration is running this instance.
        'framework' => 'standalone',
        // Writable directory for the SQLite config store, key file, uploads and buckets.
        // MUST NOT be publicly accessible from the web server.
        'storagePath' => null,
        // Location of the SQLite configuration database. Defaults to {storagePath}/grocery-restable.sqlite
        'configDatabase' => null,
        // Base directory for field uploads (set_field_upload). Defaults to {storagePath}/uploads
        'uploadPath' => null,
        // Base directory for storage buckets. Defaults to {storagePath}/buckets
        'bucketPath' => null,
        // URL prefix of the REST API and the admin GUI (used to build links).
        'apiUrl' => '/api',
        'adminUrl' => '/grocery-restable',
        'adminEnabled' => true,
        // Show exception messages / traces in API errors. Never enable in production.
        'debug' => false,
        // Key used to encrypt secrets stored in SQLite (DB passwords, JWT secret).
        // Accepts "base64:...", "hex:..." or any string with at least 32 characters.
        // When empty a random key file is generated in {storagePath}/grocery-restable.key
        'encryptionKey' => '',
        // When set, the web setup wizard requires ?token=<setupToken>.
        // When empty, the wizard is only reachable from trustedSetupIps.
        'setupToken' => '',
        'trustedSetupIps' => ['127.0.0.1', '::1'],
        // Overrides the JWT secret stored in SQLite (useful to keep it in .env).
        'jwtSecret' => '',
        // Hard limits.
        'maxEmbedDepth' => 3,
        'maxBulkRows' => 1000,
        // Optional logger: callable(string $level, string $message, array $context): void
        'logger' => null,
    ];

    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values = [])
    {
        $unknown = array_diff_key($values, self::DEFAULTS);
        if ($unknown !== []) {
            throw new ConfigurationException('Unknown Grocery Restable config option(s): ' . implode(', ', array_keys($unknown)));
        }

        $this->values = array_replace(self::DEFAULTS, $values);

        if (empty($this->values['storagePath'])) {
            throw new ConfigurationException('Grocery Restable requires a writable "storagePath".');
        }

        $storage = rtrim((string) $this->values['storagePath'], '/\\');
        $this->values['storagePath'] = $storage;
        $this->values['configDatabase'] ??= $storage . DIRECTORY_SEPARATOR . 'grocery-restable.sqlite';
        $this->values['uploadPath'] ??= $storage . DIRECTORY_SEPARATOR . 'uploads';
        $this->values['bucketPath'] ??= $storage . DIRECTORY_SEPARATOR . 'buckets';
        $this->values['apiUrl'] = rtrim((string) $this->values['apiUrl'], '/');
        $this->values['adminUrl'] = rtrim((string) $this->values['adminUrl'], '/');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function storagePath(): string
    {
        return $this->values['storagePath'];
    }

    public function configDatabase(): string
    {
        return $this->values['configDatabase'];
    }

    public function uploadPath(): string
    {
        return rtrim($this->values['uploadPath'], '/\\');
    }

    public function bucketPath(): string
    {
        return rtrim($this->values['bucketPath'], '/\\');
    }

    public function apiUrl(): string
    {
        return $this->values['apiUrl'];
    }

    public function adminUrl(): string
    {
        return $this->values['adminUrl'];
    }

    public function isDebug(): bool
    {
        return (bool) $this->values['debug'];
    }

    public function framework(): string
    {
        return (string) $this->values['framework'];
    }

    /**
     * Frameworks that can be chosen in the setup wizard. "No framework" is always possible;
     * a framework integration only when the library actually runs inside that framework.
     *
     * @return array<string, array{label: string, available: bool, detected: bool}>
     */
    public function frameworkChoices(): array
    {
        $choices = [];
        foreach (self::FRAMEWORKS as $key => $label) {
            $detected = $key === $this->framework();
            $choices[$key] = ['label' => $label, 'available' => $key === 'standalone' || $detected, 'detected' => $detected];
        }

        return $choices;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
