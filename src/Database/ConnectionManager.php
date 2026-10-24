<?php

declare(strict_types=1);

namespace GroceryRestable\Database;

use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Store\ConfigStore;

/**
 * Resolves named database connections.
 *
 * Connections come from two sources:
 *  - "framework": registered by the framework integration (e.g. a CodeIgniter 4 database group),
 *  - "gui": defined from the admin GUI / setup wizard and stored in SQLite.
 */
final class ConnectionManager
{
    /** @var array<string, array{factory: callable(): ConnectionInterface, meta: array<string, mixed>}> */
    private array $providers = [];

    /** @var array<string, ConnectionInterface> */
    private array $instances = [];

    public function __construct(private readonly ConfigStore $store)
    {
    }

    /**
     * Register a framework provided connection.
     *
     * @param callable(): ConnectionInterface $factory
     * @param array<string, mixed> $meta Informational metadata shown in the GUI (driver, database, host, label).
     */
    public function addProvider(string $name, callable $factory, array $meta = []): void
    {
        $this->providers[$name] = ['factory' => $factory, 'meta' => $meta];
        unset($this->instances[$name]);
    }

    public function isFrameworkConnection(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]) || $this->store->connection($name) !== null;
    }

    public function defaultName(): string
    {
        return (string) $this->store->setting('default_connection', 'default');
    }

    public function get(?string $name = null): ConnectionInterface
    {
        $name ??= $this->defaultName();

        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (isset($this->providers[$name])) {
            return $this->instances[$name] = ($this->providers[$name]['factory'])();
        }

        $config = $this->store->connection($name);
        if ($config === null) {
            throw new ConfigurationException('Database connection "' . $name . '" is not configured.');
        }

        return $this->instances[$name] = ConnectionFactory::make($config);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $list = [];
        foreach ($this->providers as $name => $provider) {
            $list[$name] = ['name' => $name, 'source' => 'framework'] + $provider['meta'];
        }
        foreach ($this->store->connections() as $name => $config) {
            if (isset($list[$name])) {
                continue;
            }
            unset($config['password']);
            $list[$name] = ['source' => 'gui'] + $config;
        }
        $default = $this->defaultName();
        foreach ($list as $name => &$meta) {
            $meta['default'] = $name === $default;
        }
        ksort($list);

        return $list;
    }

    public function forget(string $name): void
    {
        unset($this->instances[$name]);
    }

    /**
     * Try a connection definition. Returns null on success or an error message.
     *
     * @param array<string, mixed> $config
     */
    public static function test(array $config): ?string
    {
        try {
            $connection = ConnectionFactory::make($config);
            $connection->select('SELECT 1');

            return null;
        } catch (\Throwable $e) {
            // PDO messages may contain the host / user name but never the password.
            return $e->getMessage();
        }
    }
}
