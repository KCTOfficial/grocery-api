<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Support;

use GroceryRestable\GroceryRestable;
use GroceryRestable\Http\Response;
use PHPUnit\Framework\TestCase;

abstract class ApiTestCase extends TestCase
{
    protected string $dir;
    protected GroceryRestable $api;

    /**
     * @return array<string, mixed>
     */
    protected function settings(): array
    {
        return ['auth_mode' => 'none'];
    }

    protected function setUp(): void
    {
        $this->dir = Fixture::directory();
        $this->api = Fixture::api($this->dir, $this->settings());
    }

    protected function tearDown(): void
    {
        unset($this->api);
        gc_collect_cycles();
        Fixture::remove($this->dir);
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: mixed, 2: Response}
     */
    protected function call(string $method, string $uri, mixed $body = null, array $headers = [], array $files = [], array $form = []): array
    {
        return Fixture::call($this->api, $method, $uri, $body, $headers, $files, $form);
    }

    protected function db(): \PDO
    {
        $pdo = new \PDO('sqlite:' . $this->dir . DIRECTORY_SEPARATOR . 'app.sqlite');
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return $pdo;
    }
}
