<?php

declare(strict_types=1);

namespace GroceryRestable\Database\Schema;

use GroceryRestable\Database\ConnectionInterface;
use GroceryRestable\Exception\ConfigurationException;

abstract class SchemaInspector
{
    public function __construct(protected readonly ConnectionInterface $connection)
    {
    }

    public static function for(ConnectionInterface $connection): self
    {
        return match ($connection->driver()) {
            'mysql' => new MySqlInspector($connection),
            'pgsql' => new PostgresInspector($connection),
            'sqlite' => new SqliteInspector($connection),
            default => throw new ConfigurationException('Schema introspection is not supported for driver "' . $connection->driver() . '".'),
        };
    }

    /**
     * Describe every table and view of the current database / schema.
     *
     * @return array<string, Table>
     */
    abstract public function inspect(): array;

    /**
     * A cheap value that changes whenever tables, columns or keys change (one query).
     * Used to invalidate the schema cache immediately after migrations.
     */
    abstract public function fingerprint(): string;

    /**
     * Helper used by the concrete inspectors to assemble Table objects.
     *
     * @param array<string, array{type: string, comment: string}> $tables
     * @param array<string, array<string, Column>> $columns
     * @param array<string, list<string>> $primaryKeys
     * @param array<string, list<ForeignKey>> $foreignKeys
     * @return array<string, Table>
     */
    protected function assemble(array $tables, array $columns, array $primaryKeys, array $foreignKeys): array
    {
        $result = [];
        foreach ($tables as $name => $meta) {
            $result[$name] = new Table(
                $name,
                $meta['type'],
                $columns[$name] ?? [],
                $primaryKeys[$name] ?? [],
                $foreignKeys[$name] ?? [],
                $meta['comment'],
            );
        }
        ksort($result, SORT_NATURAL | SORT_FLAG_CASE);

        return $result;
    }

    protected static function lengthFromType(string $type): ?int
    {
        return preg_match('/^(?:var)?char(?:acter)?(?: varying)?\s*\((\d+)\)/i', $type, $m) ? (int) $m[1] : null;
    }
}
