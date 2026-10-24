<?php

declare(strict_types=1);

namespace GroceryRestable\Database\Grammar;

final class PostgresGrammar extends Grammar
{
    protected function quoteChar(): string
    {
        return '"';
    }

    public function ilike(string $wrappedColumn): string
    {
        return $this->castToText($wrappedColumn) . ' ILIKE ?';
    }

    public function supportsReturning(): bool
    {
        return true;
    }
}
