<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Database;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use GroceryRestable\Database\ConnectionInterface;
use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Exception\DatabaseException;
use Throwable;

/**
 * Runs Grocery Restable queries through a CodeIgniter 4 database connection, so the API
 * uses the exact configuration of app/Config/Database.php (and .env).
 */
final class CodeIgniterConnection implements ConnectionInterface
{
    private int $level = 0;

    public function __construct(private readonly BaseConnection $db)
    {
        self::normalizeDriver($db->DBDriver) !== ''
            ?: throw new ConfigurationException('Grocery Restable supports the MySQLi, Postgre and SQLite3 CodeIgniter drivers, not "' . $db->DBDriver . '".');
    }

    public static function normalizeDriver(string $driver): string
    {
        return match (strtolower($driver)) {
            'mysqli' => 'mysql',
            'postgre' => 'pgsql',
            'sqlite3' => 'sqlite',
            default => '',
        };
    }

    public function codeIgniter(): BaseConnection
    {
        return $this->db;
    }

    public function driver(): string
    {
        return self::normalizeDriver($this->db->DBDriver);
    }

    public function select(string $sql, array $bindings = []): array
    {
        $result = $this->run($sql, $bindings);

        return $result instanceof BaseResult ? $result->getResultArray() : [];
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->run($sql, $bindings);

        return $this->db->affectedRows();
    }

    public function lastInsertId(): int|string|null
    {
        $id = $this->db->insertID();

        return $id === 0 || $id === '' || $id === false ? null : $id;
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->level === 0) {
            $this->db->transBegin();
        }
        $this->level++;
        try {
            $result = $callback($this);
            $this->level--;
            if ($this->level === 0) {
                $this->db->transCommit();
            }

            return $result;
        } catch (Throwable $e) {
            $this->level--;
            if ($this->level === 0) {
                $this->db->transRollback();
            }
            throw $e;
        }
    }

    public function databaseName(): ?string
    {
        return $this->db->getDatabase();
    }

    /**
     * @param list<mixed> $bindings
     */
    private function run(string $sql, array $bindings): mixed
    {
        // CodeIgniter escapes positional bindings itself.
        $bindings = array_map(static fn (mixed $v): mixed => is_float($v) ? (string) $v : $v, array_values($bindings));

        try {
            $result = $bindings === [] ? $this->db->query($sql) : $this->db->query($sql, $bindings);
        } catch (Throwable $e) {
            throw new DatabaseException($e->getMessage(), self::sqlState((int) $e->getCode(), $e->getMessage()), $e);
        }

        if ($result === false) {
            $error = $this->db->error();
            throw new DatabaseException((string) ($error['message'] ?? 'Database error'), self::sqlState((int) ($error['code'] ?? 0), (string) ($error['message'] ?? '')));
        }

        return $result;
    }

    /**
     * Best effort SQLSTATE class from driver specific error codes / messages.
     */
    private static function sqlState(int $code, string $message): string
    {
        // MySQL: duplicate entry, foreign key, not null; SQLite: SQLITE_CONSTRAINT (19)
        if (in_array($code, [1062, 1451, 1452, 1216, 1217, 1048, 1364, 19], true)
            || (bool) preg_match('/(duplicate key|violates|constraint|UNIQUE constraint|FOREIGN KEY)/i', $message)) {
            return '23000';
        }
        if (in_array($code, [1366, 1264, 1292, 1406], true) || (bool) preg_match('/invalid input syntax|out of range/i', $message)) {
            return '22000';
        }

        return 'HY000';
    }
}
