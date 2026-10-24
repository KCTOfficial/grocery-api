<?php

declare(strict_types=1);

namespace GroceryRestable\Resource;

use GroceryRestable\Database\ConnectionInterface;
use GroceryRestable\Database\Grammar\Grammar;
use GroceryRestable\Database\Schema\Table;
use GroceryRestable\Exception\ApiException;

/**
 * A resource bound to its real table and database connection.
 */
final class ResolvedResource
{
    public function __construct(
        public readonly Resource $resource,
        public readonly Table $table,
        public readonly ConnectionInterface $db,
        public readonly Grammar $grammar,
        public readonly string $connectionName,
    ) {
    }

    public function name(): string
    {
        return $this->resource->getName();
    }

    public function primaryKey(): ?string
    {
        return $this->resource->primaryKey($this->table);
    }

    public function requirePrimaryKey(): string
    {
        return $this->primaryKey()
            ?? throw new ApiException('Resource "' . $this->name() . '" has no primary key; single record operations are unavailable.', 405, 'no_primary_key');
    }

    public function wrappedTable(): string
    {
        return $this->grammar->wrapTable($this->table->name);
    }
}
