<?php

declare(strict_types=1);

namespace GroceryRestable\Database\Grammar;

final class SqliteGrammar extends Grammar
{
    protected function quoteChar(): string
    {
        return '"';
    }

    public function limitOffset(?int $limit, ?int $offset): string
    {
        // SQLite requires LIMIT when OFFSET is used.
        if ($limit === null && $offset !== null && $offset > 0) {
            $limit = -1;
        }

        $sql = $limit !== null ? ' LIMIT ' . $limit : '';
        if ($offset !== null && $offset > 0) {
            $sql .= ' OFFSET ' . $offset;
        }

        return $sql;
    }
}
