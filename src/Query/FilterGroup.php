<?php

declare(strict_types=1);

namespace GroceryRestable\Query;

/**
 * Logical group of filters ("and" / "or"), possibly negated and nested.
 */
final class FilterGroup
{
    /**
     * @param list<Filter|FilterGroup> $items
     */
    public function __construct(
        public readonly string $boolean = 'and',
        public array $items = [],
        public readonly bool $negated = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function add(Filter|FilterGroup $item): void
    {
        $this->items[] = $item;
    }
}
