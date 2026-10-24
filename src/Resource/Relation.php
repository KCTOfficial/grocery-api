<?php

declare(strict_types=1);

namespace GroceryRestable\Resource;

use GroceryRestable\Exception\ConfigurationException;

/**
 * A relation between a resource table and another table.
 *
 *  - belongs_to   (Grocery CRUD set_relation):     this.field -> table.ownerKey
 *  - has_many     (reverse foreign key):           table.foreignKey -> this.ownerKey
 *  - many_to_many (Grocery CRUD set_relation_n_n): this.pk <- junction.thisKey / junction.otherKey -> table.pk
 */
final class Relation
{
    public const BELONGS_TO = 'belongs_to';
    public const HAS_MANY = 'has_many';
    public const MANY_TO_MANY = 'many_to_many';
    public const TYPES = [self::BELONGS_TO, self::HAS_MANY, self::MANY_TO_MANY];

    /**
     * @param array{0: string, 1: string}|null $orderBy [column, direction]
     */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $table,
        public readonly ?string $field = null,
        public readonly ?string $foreignKey = null,
        public readonly ?string $ownerKey = null,
        public readonly ?string $junctionTable = null,
        public readonly ?string $junctionThisKey = null,
        public readonly ?string $junctionOtherKey = null,
        public readonly ?string $titleField = null,
        public readonly ?string $priorityField = null,
        public readonly ?Conditions $where = null,
        public readonly ?string $rawWhere = null,
        public readonly ?array $orderBy = null,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new ConfigurationException('Unknown relation type "' . $type . '".');
        }
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new ConfigurationException('Invalid relation name "' . $name . '".');
        }
        foreach ([$table, $field, $foreignKey, $ownerKey, $junctionTable, $junctionThisKey, $junctionOtherKey, $priorityField] as $identifier) {
            if ($identifier !== null && ! preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $identifier)) {
                throw new ConfigurationException('Invalid identifier "' . $identifier . '" in relation "' . $name . '".');
            }
        }
        match ($type) {
            self::BELONGS_TO => $field ?? throw new ConfigurationException('belongs_to relation "' . $name . '" requires a field.'),
            self::HAS_MANY => $foreignKey ?? throw new ConfigurationException('has_many relation "' . $name . '" requires a foreign key.'),
            self::MANY_TO_MANY => ($junctionTable && $junctionThisKey && $junctionOtherKey)
                ?: throw new ConfigurationException('many_to_many relation "' . $name . '" requires junction table and keys.'),
        };
    }

    public function isBelongsTo(): bool
    {
        return $this->type === self::BELONGS_TO;
    }

    public function isHasMany(): bool
    {
        return $this->type === self::HAS_MANY;
    }

    public function isManyToMany(): bool
    {
        return $this->type === self::MANY_TO_MANY;
    }

    /**
     * Columns of the related table referenced by the title template.
     *
     * @return list<string>
     */
    public function titleColumns(): array
    {
        if ($this->titleField === null || $this->titleField === '') {
            return [];
        }
        if (str_contains($this->titleField, '{')) {
            preg_match_all('/\{([A-Za-z0-9_]+)\}/', $this->titleField, $m);

            return array_values(array_unique($m[1]));
        }

        return [$this->titleField];
    }

    /**
     * Render the title of a related row ("{lastName} {firstName}" or a single column).
     *
     * @param array<string, mixed> $row
     */
    public function renderTitle(array $row): string
    {
        if ($this->titleField === null || $this->titleField === '') {
            return '';
        }
        if (! str_contains($this->titleField, '{')) {
            return (string) ($row[$this->titleField] ?? '');
        }

        return (string) preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', static fn (array $m): string => (string) ($row[$m[1]] ?? ''), $this->titleField);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'name' => $this->name,
            'table' => $this->table,
            'field' => $this->field,
            'foreignKey' => $this->foreignKey,
            'ownerKey' => $this->ownerKey,
            'junctionTable' => $this->junctionTable,
            'junctionThisKey' => $this->junctionThisKey,
            'junctionOtherKey' => $this->junctionOtherKey,
            'titleField' => $this->titleField,
            'priorityField' => $this->priorityField,
            'where' => $this->where?->toArray() ?: null,
            'orderBy' => $this->orderBy,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * Build from a stored (GUI) definition. Raw SQL where clauses are not accepted from storage.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $orderBy = $data['orderBy'] ?? null;

        return new self(
            type: (string) ($data['type'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            table: (string) ($data['table'] ?? ''),
            field: self::nullable($data['field'] ?? null),
            foreignKey: self::nullable($data['foreignKey'] ?? null),
            ownerKey: self::nullable($data['ownerKey'] ?? null),
            junctionTable: self::nullable($data['junctionTable'] ?? null),
            junctionThisKey: self::nullable($data['junctionThisKey'] ?? null),
            junctionOtherKey: self::nullable($data['junctionOtherKey'] ?? null),
            titleField: self::nullable($data['titleField'] ?? null),
            priorityField: self::nullable($data['priorityField'] ?? null),
            where: ! empty($data['where']) && is_array($data['where']) ? Conditions::fromArray($data['where']) : null,
            orderBy: is_array($orderBy) && isset($orderBy[0]) ? [(string) $orderBy[0], strtolower((string) ($orderBy[1] ?? 'asc')) === 'desc' ? 'desc' : 'asc'] : null,
        );
    }

    /**
     * Normalize the Grocery CRUD order_by argument ("title", "title desc").
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseOrderBy(?string $orderBy): ?array
    {
        if ($orderBy === null || trim($orderBy) === '') {
            return null;
        }
        $parts = preg_split('/\s+/', trim($orderBy));

        return [$parts[0], strtolower($parts[1] ?? 'asc') === 'desc' ? 'desc' : 'asc'];
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
