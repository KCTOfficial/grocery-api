<?php

declare(strict_types=1);

namespace GroceryRestable\Database\Schema;

final class ForeignKey
{
    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly array $columns,
        public readonly string $referencedTable,
        public readonly array $referencedColumns,
    ) {
    }

    public function isSingleColumn(): bool
    {
        return count($this->columns) === 1 && count($this->referencedColumns) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'table' => $this->table,
            'columns' => $this->columns,
            'referencedTable' => $this->referencedTable,
            'referencedColumns' => $this->referencedColumns,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['table'], $data['columns'], $data['referencedTable'], $data['referencedColumns']);
    }
}
