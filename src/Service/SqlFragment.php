<?php

declare(strict_types=1);

namespace GroceryRestable\Service;

/**
 * A piece of SQL together with its positional bindings.
 */
final class SqlFragment
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        public readonly string $sql = '',
        public readonly array $bindings = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->sql === '';
    }

    /**
     * Combine non-empty fragments with AND.
     */
    public static function and(self ...$fragments): self
    {
        $sql = [];
        $bindings = [];
        foreach ($fragments as $fragment) {
            if ($fragment->isEmpty()) {
                continue;
            }
            $sql[] = '(' . $fragment->sql . ')';
            array_push($bindings, ...$fragment->bindings);
        }

        return new self(implode(' AND ', $sql), $bindings);
    }

    public function toWhere(): string
    {
        return $this->isEmpty() ? '' : ' WHERE ' . $this->sql;
    }
}
