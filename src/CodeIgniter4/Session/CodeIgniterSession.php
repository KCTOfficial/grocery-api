<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Session;

use CodeIgniter\Session\SessionInterface as CodeIgniterSessionInterface;
use GroceryRestable\Admin\SessionInterface;

/**
 * Admin GUI session stored in the CodeIgniter session, namespaced under "grocery_restable".
 */
final class CodeIgniterSession implements SessionInterface
{
    private const KEY = 'grocery_restable';

    public function __construct(private readonly CodeIgniterSessionInterface $session)
    {
    }

    public function get(string $key): mixed
    {
        $data = $this->session->get(self::KEY);

        return is_array($data) ? ($data[$key] ?? null) : null;
    }

    public function set(string $key, mixed $value): void
    {
        $data = $this->session->get(self::KEY);
        $data = is_array($data) ? $data : [];
        $data[$key] = $value;
        $this->session->set(self::KEY, $data);
    }

    public function remove(string $key): void
    {
        $data = $this->session->get(self::KEY);
        if (is_array($data)) {
            unset($data[$key]);
            $this->session->set(self::KEY, $data);
        }
    }

    public function regenerate(): void
    {
        $this->session->regenerate(true);
    }
}
