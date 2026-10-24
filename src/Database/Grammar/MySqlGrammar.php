<?php

declare(strict_types=1);

namespace GroceryRestable\Database\Grammar;

final class MySqlGrammar extends Grammar
{
    protected function quoteChar(): string
    {
        return '`';
    }

    public function castToText(string $wrappedColumn): string
    {
        return 'CAST(' . $wrappedColumn . ' AS CHAR)';
    }

    public function orderBy(string $wrappedColumn, string $direction, ?string $nulls): string
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        if ($nulls === null) {
            return $wrappedColumn . ' ' . $direction;
        }

        // MySQL has no NULLS FIRST/LAST: sort on "IS NULL" first.
        return $wrappedColumn . ' IS NULL ' . ($nulls === 'first' ? 'DESC' : 'ASC') . ', ' . $wrappedColumn . ' ' . $direction;
    }
}
