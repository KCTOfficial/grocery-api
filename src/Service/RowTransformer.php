<?php

declare(strict_types=1);

namespace GroceryRestable\Service;

use GroceryRestable\Database\Schema\Table;
use GroceryRestable\Resource\Resource;

/**
 * Converts database rows into API output: native JSON types, decoded JSON / SET columns
 * and Grocery CRUD style callbackColumn() transformations.
 */
final class RowTransformer
{
    /**
     * @param array<string, mixed> $row Raw row (may contain helper columns).
     * @param list<array{column: string, alias: string}> $columns Output columns.
     * @return array<string, mixed>
     */
    public function transform(?Resource $resource, Table $table, array $row, array $columns): array
    {
        $output = [];
        foreach ($columns as ['column' => $column, 'alias' => $alias]) {
            $value = $row[$column] ?? null;
            $type = $resource?->getFieldType($column)['type'] ?? $table->columns[$column]->type ?? 'string';
            $value = $this->cast($value, $type, $table->columns[$column]->type ?? 'string');

            if ($resource !== null && ($callback = $resource->getColumnCallback($column)) !== null) {
                $value = $callback($value, $row);
            }
            $output[$alias] = $value;
        }

        return $output;
    }

    public function cast(mixed $value, string $type, string $schemaType): mixed
    {
        if ($value === null) {
            return null;
        }

        switch ($type) {
            case 'boolean':
            case 'true_false':
                if (is_bool($value)) {
                    return $value;
                }

                return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on', 'y'], true);

            case 'integer':
                if (is_int($value)) {
                    return $value;
                }

                return is_string($value) && preg_match('/^-?\d{1,18}$/', $value) ? (int) $value : $value;

            case 'number':
                if (is_int($value) || is_float($value)) {
                    return $value;
                }
                // Keep high precision decimals as strings to avoid silent rounding.
                if (is_string($value) && is_numeric($value) && strlen(str_replace(['-', '.'], '', $value)) <= 15) {
                    return str_contains($value, '.') || stripos($value, 'e') !== false ? (float) $value : (int) $value;
                }

                return $value;

            case 'json':
                if (is_string($value)) {
                    $decoded = json_decode($value, true);

                    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
                }

                return $value;

            case 'set':
            case 'multiselect':
                return $value === '' ? [] : explode(',', (string) $value);

            default:
                if ($schemaType === 'binary' && is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                    return base64_encode($value);
                }
                if (is_resource($value)) {
                    return base64_encode((string) stream_get_contents($value));
                }

                return $value;
        }
    }
}
