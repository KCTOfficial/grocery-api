<?php

declare(strict_types=1);

namespace GroceryRestable\Database\Schema;

final class Column
{
    /**
     * Normalized data types used for validation, casting and OpenAPI generation.
     */
    public const TYPES = ['integer', 'number', 'boolean', 'string', 'text', 'date', 'datetime', 'time', 'json', 'uuid', 'enum', 'binary'];

    /**
     * @param list<string> $enumValues
     */
    public function __construct(
        public readonly string $name,
        public readonly string $nativeType,
        public readonly string $type,
        public readonly bool $nullable = true,
        public readonly mixed $default = null,
        public readonly ?int $maxLength = null,
        public readonly bool $primary = false,
        public readonly bool $autoIncrement = false,
        public readonly array $enumValues = [],
        public readonly string $comment = '',
        public readonly bool $generated = false,
    ) {
    }

    /**
     * Whether an INSERT may omit this column.
     */
    public function isOptionalOnInsert(): bool
    {
        return $this->nullable || $this->default !== null || $this->autoIncrement || $this->generated;
    }

    /**
     * Map a native database type to a normalized type.
     */
    public static function normalizeType(string $nativeType): string
    {
        $t = strtolower(trim($nativeType));

        return match (true) {
            $t === 'tinyint(1)', $t === 'bit(1)', str_starts_with($t, 'bool') => 'boolean',
            (bool) preg_match('/^(tiny|small|medium|big)?int|^integer|^serial|^bigserial|^smallserial|^int[248]|^year/', $t) => 'integer',
            (bool) preg_match('/^(decimal|numeric|float|double|real|money|dec\b|fixed)/', $t) => 'number',
            str_starts_with($t, 'enum') => 'enum',
            (bool) preg_match('/^(timestamp|datetime)/', $t) => 'datetime',
            $t === 'date' => 'date',
            (bool) preg_match('/^time/', $t) => 'time',
            (bool) preg_match('/^jsonb?$/', $t) => 'json',
            $t === 'uuid' => 'uuid',
            (bool) preg_match('/(blob|binary|bytea|varbinary)/', $t) => 'binary',
            (bool) preg_match('/(text|clob)/', $t) => 'text',
            default => 'string',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'nativeType' => $this->nativeType,
            'type' => $this->type,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'maxLength' => $this->maxLength,
            'primary' => $this->primary,
            'autoIncrement' => $this->autoIncrement,
            'enumValues' => $this->enumValues,
            'comment' => $this->comment,
            'generated' => $this->generated,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['nativeType'],
            $data['type'],
            (bool) $data['nullable'],
            $data['default'],
            $data['maxLength'],
            (bool) $data['primary'],
            (bool) $data['autoIncrement'],
            $data['enumValues'] ?? [],
            $data['comment'] ?? '',
            (bool) ($data['generated'] ?? false),
        );
    }
}
