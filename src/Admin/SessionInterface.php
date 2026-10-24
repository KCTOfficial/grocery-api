<?php

declare(strict_types=1);

namespace GroceryRestable\Admin;

/**
 * Session storage used by the admin GUI. Framework adapters wrap their own session service.
 */
interface SessionInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /**
     * Regenerate the session id (prevents session fixation after login).
     */
    public function regenerate(): void;
}
