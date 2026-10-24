<?php

declare(strict_types=1);

namespace GroceryRestable\Database;

/**
 * Minimal database abstraction used by the API engine.
 *
 * All SQL produced by Grocery Restable uses positional "?" placeholders, values are
 * always passed as bindings and never concatenated into the SQL string.
 */
interface ConnectionInterface
{
    /**
     * Normalized driver name: "mysql", "pgsql" or "sqlite".
     */
    public function driver(): string;

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array;

    /**
     * Run a statement that does not return rows. Returns the number of affected rows.
     *
     * @param list<mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int;

    public function lastInsertId(): int|string|null;

    /**
     * Run the callback inside a transaction. Nested calls join the outer transaction.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;

    /**
     * Database / schema name, used for schema introspection.
     */
    public function databaseName(): ?string;
}
