<?php

declare(strict_types=1);

namespace GroceryRestable\Database\Grammar;

use GroceryRestable\Exception\ConfigurationException;

/**
 * Driver specific SQL fragments.
 *
 * Identifiers are validated against the introspected schema before they reach
 * the grammar; quoting here is an additional defense layer.
 */
abstract class Grammar
{
    abstract protected function quoteChar(): string;

    public static function for(string $driver): self
    {
        return match ($driver) {
            'mysql' => new MySqlGrammar(),
            'pgsql' => new PostgresGrammar(),
            'sqlite' => new SqliteGrammar(),
            default => throw new ConfigurationException('Unsupported database driver "' . $driver . '".'),
        };
    }

    /**
     * Quote an identifier. "alias.column" is split and each part quoted; "*" is kept.
     */
    public function wrap(string $identifier): string
    {
        $q = $this->quoteChar();

        return implode('.', array_map(
            static fn (string $part): string => $part === '*' ? '*' : $q . str_replace($q, $q . $q, $part) . $q,
            explode('.', $identifier),
        ));
    }

    public function wrapTable(string $table): string
    {
        return $this->wrap($table);
    }

    public function limitOffset(?int $limit, ?int $offset): string
    {
        $sql = '';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);
        }
        if ($offset !== null && $offset > 0) {
            $sql .= ' OFFSET ' . $offset;
        }

        return $sql;
    }

    /**
     * Case insensitive LIKE expression for a wrapped column.
     */
    public function ilike(string $wrappedColumn): string
    {
        return 'LOWER(' . $this->castToText($wrappedColumn) . ') LIKE LOWER(?)';
    }

    public function like(string $wrappedColumn): string
    {
        return $this->castToText($wrappedColumn) . ' LIKE ?';
    }

    public function castToText(string $wrappedColumn): string
    {
        return 'CAST(' . $wrappedColumn . ' AS TEXT)';
    }

    /**
     * NULLS FIRST / NULLS LAST support.
     */
    public function orderBy(string $wrappedColumn, string $direction, ?string $nulls): string
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        if ($nulls === null) {
            return $wrappedColumn . ' ' . $direction;
        }

        return $wrappedColumn . ' ' . $direction . ' NULLS ' . ($nulls === 'first' ? 'FIRST' : 'LAST');
    }

    public function supportsReturning(): bool
    {
        return false;
    }
}
