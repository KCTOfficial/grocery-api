<?php

declare(strict_types=1);

namespace GroceryRestable\Query;

/**
 * Everything a client can ask for when reading: projection, filters, order, paging.
 */
final class QueryOptions
{
    /**
     * @param list<array{column: string, direction: string, nulls: string|null}> $order
     */
    public function __construct(
        public SelectNode $select = new SelectNode(),
        public FilterGroup $filters = new FilterGroup(),
        public array $order = [],
        public ?int $limit = null,
        public ?int $offset = null,
        public bool $count = false,
        public ?string $search = null,
        public string $format = 'json',
        public ?int $page = null,
    ) {
    }
}
