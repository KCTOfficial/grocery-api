<?php

declare(strict_types=1);

namespace GroceryRestable\Database;

use GroceryRestable\Exception\ConfigurationException;
use PDO;

/**
 * Creates connections from a plain connection definition.
 *
 * "driver" is the database engine, "client" the PHP extension used to talk to it:
 *
 *   ['driver' => 'mysql', 'client' => 'pdo', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'app',
 *    'username' => 'root', 'password' => '', 'charset' => 'utf8mb4']
 *   ['driver' => 'mysql', 'client' => 'mysqli', ...]                    (native mysqli extension)
 *   ['driver' => 'pgsql', 'host' => ..., 'schema' => 'public', 'sslmode' => 'prefer']
 *   ['driver' => 'sqlite', 'database' => '/path/to/file.sqlite']
 */
final class ConnectionFactory
{
    /**
     * Database engines and the PHP extensions (clients) able to connect to them.
     */
    public const ENGINES = [
        'mysql' => ['label' => 'MySQL / MariaDB', 'supported' => true, 'clients' => ['pdo' => 'pdo_mysql', 'mysqli' => 'mysqli']],
        'pgsql' => ['label' => 'PostgreSQL', 'supported' => true, 'clients' => ['pdo' => 'pdo_pgsql']],
        'sqlite' => ['label' => 'SQLite', 'supported' => true, 'clients' => ['pdo' => 'pdo_sqlite']],
        'sqlsrv' => ['label' => 'Microsoft SQL Server', 'supported' => false, 'clients' => ['pdo' => 'pdo_sqlsrv']],
    ];

    public const CLIENTS = [
        'pdo' => 'PDO',
        'mysqli' => 'MySQLi (native)',
    ];

    /**
     * Engines / clients for user interfaces, with the installation state of each extension.
     *
     * @return array<string, array{label: string, supported: bool, clients: array<string, array{label: string, extension: string, installed: bool}>}>
     */
    public static function catalog(): array
    {
        $catalog = [];
        foreach (self::ENGINES as $engine => $definition) {
            $clients = [];
            foreach ($definition['clients'] as $client => $extension) {
                $clients[$client] = ['label' => self::CLIENTS[$client], 'extension' => $extension, 'installed' => self::isInstalled($client, $engine)];
            }
            $catalog[$engine] = ['label' => $definition['label'], 'supported' => $definition['supported'], 'clients' => $clients];
        }

        return $catalog;
    }

    public static function isInstalled(string $client, string $engine): bool
    {
        return match ($client) {
            'pdo' => extension_loaded('pdo') && in_array($engine === 'sqlsrv' ? 'sqlsrv' : $engine, PDO::getAvailableDrivers(), true),
            'mysqli' => extension_loaded('mysqli'),
            default => false,
        };
    }

    /**
     * Validate the engine / client pair and return the client.
     *
     * @param array<string, mixed> $config
     */
    public static function client(array $config): string
    {
        $engine = (string) ($config['driver'] ?? '');
        $client = (string) ($config['client'] ?? 'pdo');
        $definition = self::ENGINES[$engine] ?? null;
        if ($definition === null || ! $definition['supported']) {
            throw new ConfigurationException('Unsupported database engine "' . $engine . '".');
        }
        if (! isset($definition['clients'][$client])) {
            throw new ConfigurationException(sprintf('The %s driver cannot connect to %s.', self::CLIENTS[$client] ?? $client, $definition['label']));
        }
        if (! self::isInstalled($client, $engine)) {
            throw new ConfigurationException('The PHP extension "' . $definition['clients'][$client] . '" is not installed.');
        }

        return $client;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function make(array $config): ConnectionInterface
    {
        return self::client($config) === 'mysqli' ? self::makeMysqli($config) : self::makePdo($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function makePdo(array $config): PdoConnection
    {
        $driver = (string) $config['driver'];
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => (int) ($config['timeout'] ?? 10),
        ];

        $database = (string) ($config['database'] ?? '');

        switch ($driver) {
            case 'mysql':
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $config['host'] ?? '127.0.0.1',
                    (int) ($config['port'] ?? 3306),
                    $database,
                    $config['charset'] ?? 'utf8mb4',
                );
                $options[PDO::ATTR_EMULATE_PREPARES] = false;
                $options[PDO::ATTR_STRINGIFY_FETCHES] = false;
                break;

            case 'pgsql':
                $dsn = sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                    $config['host'] ?? '127.0.0.1',
                    (int) ($config['port'] ?? 5432),
                    $database,
                    $config['sslmode'] ?? 'prefer',
                );
                break;

            default: // sqlite
                if ($database === '') {
                    throw new ConfigurationException('SQLite connections require a database file path.');
                }
                if ($database !== ':memory:' && ! is_file($database)) {
                    throw new ConfigurationException('SQLite database file not found: ' . $database);
                }
                $dsn = 'sqlite:' . $database;
        }

        $pdo = new PDO($dsn, $config['username'] ?? null, $config['password'] ?? null, $options);

        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        } elseif ($driver === 'pgsql' && ! empty($config['schema'])) {
            $schema = (string) $config['schema'];
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
                throw new ConfigurationException('Invalid PostgreSQL schema name.');
            }
            $pdo->exec('SET search_path TO "' . $schema . '"');
        }

        return new PdoConnection($pdo, $driver === 'sqlite' ? 'main' : $database);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function makeMysqli(array $config): MysqliConnection
    {
        // Throw mysqli_sql_exception on errors (the PHP 8.1+ default, made explicit).
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $charset = (string) ($config['charset'] ?? 'utf8mb4');
        if (! preg_match('/^[A-Za-z0-9_]+$/', $charset)) {
            throw new ConfigurationException('Invalid MySQL charset.');
        }

        $mysqli = mysqli_init();
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, (int) ($config['timeout'] ?? 10));
        $mysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        $mysqli->real_connect(
            (string) ($config['host'] ?? '127.0.0.1'),
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            (string) ($config['database'] ?? ''),
            (int) ($config['port'] ?? 3306),
        );
        $mysqli->set_charset($charset);

        return new MysqliConnection($mysqli, (string) ($config['database'] ?? ''));
    }
}
