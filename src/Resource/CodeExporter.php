<?php

declare(strict_types=1);

namespace GroceryRestable\Resource;

/**
 * Turns a (GUI) resource definition into equivalent fluent PHP code.
 */
final class CodeExporter
{
    public function export(Resource $resource, string $variable = '$api'): string
    {
        $d = $resource->toArray();
        $lines = [];
        $call = static function (string $method, mixed ...$args) use (&$lines): void {
            $lines[] = '    ->' . $method . '(' . implode(', ', array_map([self::class, 'value'], $args)) . ')';
        };

        if ($d['connection'] !== null) {
            $call('setConnection', $d['connection']);
        }
        if ($d['subject'] !== null) {
            $d['subjectPlural'] !== null ? $call('setSubject', $d['subject'], $d['subjectPlural']) : $call('setSubject', $d['subject']);
        }
        if ($d['description'] !== '') {
            $call('setDescription', $d['description']);
        }
        if ($d['primaryKey'] !== null) {
            $call('setPrimaryKey', $d['primaryKey']);
        }
        foreach ($d['primaryKeys'] as $table => $pk) {
            $call('setPrimaryKey', $pk, $table);
        }
        foreach ([
            'columns' => 'columns', 'unsetColumns' => 'unsetColumns', 'readFields' => 'setReadFields', 'unsetReadFields' => 'unsetReadFields',
            'fields' => 'fields', 'addFields' => 'addFields', 'editFields' => 'editFields', 'unsetFields' => 'unsetFields',
            'unsetAddFields' => 'unsetAddFields', 'unsetEditFields' => 'unsetEditFields', 'requiredFields' => 'requiredFields',
            'uniqueFields' => 'uniqueFields', 'searchable' => 'setSearchable',
        ] as $key => $method) {
            if ($d[$key] !== []) {
                $call($method, ...$d[$key]);
            }
        }
        foreach ($d['displayAs'] as $field => $label) {
            $call('displayAs', $field, $label);
        }
        foreach ($d['fieldTypes'] as $field => $type) {
            $type['extras'] !== null ? $call('fieldType', $field, $type['type'], $type['extras']) : $call('fieldType', $field, $type['type']);
        }
        foreach ($d['rules'] as $field => $rule) {
            $rule['label'] !== null ? $call('setRules', $field, $rule['label'], $rule['rules']) : $call('setRules', $field, $rule['rules']);
        }
        foreach ($resource->getRelations() as $relation) {
            $where = $relation->where?->toArray() ?: null;
            $order = $relation->orderBy !== null ? implode(' ', $relation->orderBy) : null;
            match ($relation->type) {
                Relation::BELONGS_TO => $call(...self::trim(['setRelation', $relation->field, $relation->table, $relation->titleField ?? '', $where, $order,
                    $relation->name !== $relation->table ? $relation->name : null, $relation->ownerKey])),
                Relation::MANY_TO_MANY => $call(...self::trim(['setRelationNtoN', $relation->name, $relation->junctionTable, $relation->table,
                    $relation->junctionThisKey, $relation->junctionOtherKey, $relation->titleField ?? '', $relation->priorityField, $where])),
                default => $call(...self::trim(['setRelationHasMany', $relation->name, $relation->table, $relation->foreignKey,
                    $relation->titleField, $where, $order, $relation->ownerKey])),
            };
        }
        foreach ($resource->getUploads() as $upload) {
            $call(...self::trim(['setFieldUpload', $upload->field, $upload->path, $upload->allowedTypes ?? '', $upload->maxSize]));
        }
        $defaults = ['list' => true, 'read' => true, 'create' => true, 'update' => true, 'delete' => true, 'clone' => false, 'export' => true];
        $methods = ['list' => 'unsetList', 'read' => 'unsetRead', 'create' => 'unsetAdd', 'update' => 'unsetEdit', 'delete' => 'unsetDelete', 'export' => 'unsetExport'];
        foreach ($d['operations'] as $operation => $enabled) {
            if ($enabled === ($defaults[$operation] ?? true)) {
                continue;
            }
            if ($operation === 'clone') {
                $enabled ? $call('setClone') : $call('unsetClone');
            } elseif (! $enabled && isset($methods[$operation])) {
                $call($methods[$operation]);
            }
        }
        foreach ($d['where'] as $condition) {
            $method = $condition['boolean'] === 'or' ? 'orWhere' : 'where';
            if ($condition['operator'] === '=') {
                $call($method, $condition['column'], $condition['value']);
            } elseif ($condition['operator'] === 'in') {
                $call($condition['boolean'] === 'or' ? 'orWhere' : 'where', $condition['column'], $condition['value']);
            } else {
                $call($method, $condition['column'], $condition['operator'], $condition['value']);
            }
        }
        foreach ($d['orderBy'] as [$column, $direction]) {
            $call('orderBy', $column, $direction);
        }
        if ($d['perPage'] !== null) {
            $call('limit', $d['perPage']);
        }
        if ($d['maxLimit'] !== null) {
            $call('setMaxLimit', $d['maxLimit']);
        }
        if ($d['permissions'] !== null) {
            $call('setPermissions', $d['permissions']);
        }

        $head = $variable . '->resource(' . self::value($d['name']) . ($d['table'] !== $d['name'] ? ', ' . self::value($d['table']) : '') . ')';

        return $head . ($lines !== [] ? "\n" . implode("\n", $lines) : '') . ";\n";
    }

    /**
     * Drop trailing null arguments.
     *
     * @param list<mixed> $args
     * @return list<mixed>
     */
    private static function trim(array $args): array
    {
        while ($args !== [] && end($args) === null) {
            array_pop($args);
        }

        return $args;
    }

    public static function value(mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            $isList = array_is_list($value);
            $items = [];
            foreach ($value as $key => $item) {
                $items[] = ($isList ? '' : self::value($key) . ' => ') . self::value($item);
            }

            return '[' . implode(', ', $items) . ']';
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => "'" . addcslashes((string) $value, "'\\") . "'",
        };
    }
}
