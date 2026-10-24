<?php

declare(strict_types=1);

namespace GroceryRestable\Query;

/**
 * A single filter: [relation.]column [not.]operator value
 */
final class Filter
{
    public const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like', 'ilike', 'in', 'is'];

    /**
     * @param string|list<string>|null|bool $value
     */
    public function __construct(
        public readonly string $column,
        public readonly string $operator,
        public readonly mixed $value,
        public readonly bool $negated = false,
        public readonly ?string $relation = null,
    ) {
    }
}
