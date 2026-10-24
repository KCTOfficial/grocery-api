<?php

declare(strict_types=1);

namespace GroceryRestable\Database;

use GroceryRestable\Exception\DatabaseException;
use mysqli;
use mysqli_sql_exception;
use mysqli_stmt;
use Throwable;

/**
 * MySQL / MariaDB connection through the native mysqli extension (alternative to pdo_mysql).
 * Every query is a real prepared statement.
 */
final class MysqliConnection implements ConnectionInterface
{
    private int $level = 0;

    public function __construct(
        private readonly mysqli $db,
        private readonly ?string $database = null,
    ) {
    }

    public function mysqli(): mysqli
    {
        return $this->db;
    }

    public function driver(): string
    {
        return 'mysql';
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->run(function () use ($sql, $bindings): array {
            $statement = $this->prepare($sql, $bindings);
            $statement->execute();
            $result = $statement->get_result();
            $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
            $statement->close();

            return $rows;
        });
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run(function () use ($sql, $bindings): int {
            $statement = $this->prepare($sql, $bindings);
            $statement->execute();
            $affected = (int) $statement->affected_rows;
            $statement->close();

            return max(0, $affected);
        });
    }

    public function lastInsertId(): int|string|null
    {
        $id = $this->db->insert_id;

        return $id === 0 || $id === '0' || $id === '' ? null : $id;
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->level === 0) {
            $this->run(fn (): bool => $this->db->begin_transaction());
        }
        $this->level++;
        try {
            $result = $callback($this);
            $this->level--;
            if ($this->level === 0) {
                $this->run(fn (): bool => $this->db->commit());
            }

            return $result;
        } catch (Throwable $e) {
            $this->level--;
            if ($this->level === 0) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    public function databaseName(): ?string
    {
        return $this->database;
    }

    /**
     * @param list<mixed> $bindings
     */
    private function prepare(string $sql, array $bindings): mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if ($bindings === []) {
            return $statement;
        }

        $types = '';
        $values = [];
        foreach (array_values($bindings) as $value) {
            if (is_bool($value)) {
                $value = (int) $value;
            }
            $types .= match (true) {
                is_int($value) => 'i',
                is_float($value) => 'd',
                default => 's',
            };
            $values[] = $value === null ? null : (is_int($value) || is_float($value) ? $value : (string) $value);
        }
        $statement->bind_param($types, ...$values);

        return $statement;
    }

    /**
     * Convert mysqli exceptions into DatabaseException (with SQLSTATE) for HTTP error mapping.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function run(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (mysqli_sql_exception $e) {
            // "Field has no default value" (1364) is a NOT NULL constraint violation reported as HY000.
            $state = $e->getCode() === 1364 ? '23000' : $e->getSqlState();
            throw new DatabaseException($e->getMessage(), $state, $e);
        }
    }
}
