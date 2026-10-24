<?php

declare(strict_types=1);

namespace GroceryRestable\Database;

use PDO;
use Throwable;

final class PdoConnection implements ConnectionInterface
{
    private int $transactionLevel = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?string $database = null,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $bindings);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $bindings);
        $statement->execute();

        return $statement->rowCount();
    }

    public function lastInsertId(): int|string|null
    {
        $id = $this->pdo->lastInsertId();
        if ($id === false || $id === '' || $id === '0') {
            return null;
        }

        return ctype_digit($id) ? (int) $id : $id;
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
        }
        $this->transactionLevel++;

        try {
            $result = $callback($this);
            $this->transactionLevel--;
            if ($this->transactionLevel === 0) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            $this->transactionLevel--;
            if ($this->transactionLevel === 0 && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
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
    private function bind(\PDOStatement $statement, array $bindings): void
    {
        $isPgsql = $this->driver() === 'pgsql';
        foreach (array_values($bindings) as $i => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => $isPgsql ? PDO::PARAM_BOOL : PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            if (is_bool($value) && ! $isPgsql) {
                $value = (int) $value;
            } elseif (is_float($value)) {
                $value = (string) $value;
            }
            $statement->bindValue($i + 1, $value, $type);
        }
    }
}
