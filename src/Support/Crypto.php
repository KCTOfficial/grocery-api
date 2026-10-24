<?php

declare(strict_types=1);

namespace GroceryRestable\Support;

use GroceryRestable\Exception\ConfigurationException;

/**
 * Symmetric encryption (libsodium secretbox) for secrets stored in SQLite.
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';

    private string $key;

    public function __construct(string $key)
    {
        $this->key = self::normalizeKey($key);
    }

    /**
     * Resolve the key from configuration or from (and, if needed, create) a key file.
     */
    public static function fromConfig(string $configuredKey, string $keyFile): self
    {
        if ($configuredKey !== '') {
            return new self($configuredKey);
        }

        if (is_file($keyFile)) {
            $stored = trim((string) file_get_contents($keyFile));
            if ($stored === '') {
                throw new ConfigurationException('The Grocery Restable key file is empty: ' . $keyFile);
            }

            return new self($stored);
        }

        $dir = dirname($keyFile);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new ConfigurationException('Unable to create directory ' . $dir);
        }

        $generated = 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        if (file_put_contents($keyFile, $generated, LOCK_EX) === false) {
            throw new ConfigurationException('Unable to write the Grocery Restable key file: ' . $keyFile);
        }
        @chmod($keyFile, 0600);

        return new self($generated);
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || ! str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new ConfigurationException('Unable to decrypt a stored secret (corrupted value).');
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        if ($plain === false) {
            throw new ConfigurationException('Unable to decrypt a stored secret. Did the encryption key change?');
        }

        return $plain;
    }

    private static function normalizeKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false) {
                throw new ConfigurationException('Invalid base64 encryption key.');
            }
            $key = $decoded;
        } elseif (str_starts_with($key, 'hex:')) {
            $decoded = @hex2bin(substr($key, 4));
            if ($decoded === false) {
                throw new ConfigurationException('Invalid hex encryption key.');
            }
            $key = $decoded;
        }

        if (strlen($key) < 32) {
            throw new ConfigurationException('The Grocery Restable encryption key must be at least 32 bytes long.');
        }

        // Derive a fixed-size key so any sufficiently long secret can be used.
        return sodium_crypto_generichash($key, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
