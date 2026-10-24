<?php

declare(strict_types=1);

namespace GroceryRestable\Database\Schema;

final class Table
{
    /**
     * @param array<string, Column> $columns
     * @param list<string> $primaryKey
     * @param list<ForeignKey> $foreignKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $columns,
        public readonly array $primaryKey = [],
        public readonly array $foreignKeys = [],
        public readonly string $comment = '',
    ) {
    }

    public function isView(): bool
    {
        return $this->type === 'view';
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columns[$name]);
    }

    public function column(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_keys($this->columns);
    }

    /**
     * The single-column primary key, if any.
     */
    public function singlePrimaryKey(): ?string
    {
        return count($this->primaryKey) === 1 ? $this->primaryKey[0] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'columns' => array_map(static fn (Column $c): array => $c->toArray(), array_values($this->columns)),
            'primaryKey' => $this->primaryKey,
            'foreignKeys' => array_map(static fn (ForeignKey $f): array => $f->toArray(), $this->foreignKeys),
            'comment' => $this->comment,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $columns = [];
        foreach ($data['columns'] as $column) {
            $columns[$column['name']] = Column::fromArray($column);
        }

        return new self(
            $data['name'],
            $data['type'],
            $columns,
            $data['primaryKey'],
            array_map(static fn (array $f): ForeignKey => ForeignKey::fromArray($f), $data['foreignKeys']),
            $data['comment'] ?? '',
        );
    }
}
