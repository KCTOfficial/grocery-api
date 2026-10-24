<?php

declare(strict_types=1);

namespace GroceryRestable\Admin;

/**
 * PHP native session, namespaced under $_SESSION['grocery_restable'].
 */
final class NativeSession implements SessionInterface
{
    private const NAMESPACE = 'grocery_restable';

    public function __construct(private readonly string $name = 'grocery_restable_admin')
    {
    }

    public function get(string $key): mixed
    {
        $this->start();

        return $_SESSION[self::NAMESPACE][$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[self::NAMESPACE][$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[self::NAMESPACE][$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    /**
     * Write and close the session (no-op when it was never started).
     */
    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            $_SESSION ??= [];

            return;
        }
        $secure = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name($this->name);
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $secure, 'path' => '/']);
        session_start(['use_strict_mode' => true]);
    }
}
