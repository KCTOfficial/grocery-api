<?php

declare(strict_types=1);

namespace GroceryRestable\Service;

use GroceryRestable\Database\Grammar\Grammar;
use GroceryRestable\Database\Schema\Column;
use GroceryRestable\Database\Schema\Table;
use GroceryRestable\Exception\ApiException;
use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Query\Filter;
use GroceryRestable\Query\FilterGroup;
use GroceryRestable\Resource\Conditions;
use GroceryRestable\Resource\Relation;
use GroceryRestable\Resource\ResolvedResource;

/**
 * Compiles conditions and client filters into parameterized SQL.
 *
 * Every column name is checked against an allow-list built from the schema and the
 * resource definition, and every value is bound as a parameter.
 */
final class SqlCompiler
{
    /**
     * @param callable(Relation): array{0: Table, 1: list<string>, 2: SqlFragment|null} $relationResolver
     *        Returns the related table, its filterable columns and its extra row conditions.
     */
    public function __construct(
        private readonly Grammar $grammar,
        private readonly mixed $relationResolver = null,
    ) {
    }

    /**
     * Developer defined conditions (where(), relation where, scopes).
     */
    public function conditions(Conditions $conditions, Table $table, string $alias): SqlFragment
    {
        $sql = '';
        $bindings = [];
        foreach ($conditions->items() as $i => $item) {
            if (! $table->hasColumn($item['column'])) {
                throw new ConfigurationException(sprintf('Condition column "%s" does not exist in table "%s".', $item['column'], $table->name));
            }
            $column = $this->grammar->wrap($alias . '.' . $item['column']);
            $value = $item['value'];

            switch ($item['operator']) {
                case 'in':
                case 'not in':
                    if ($value === []) {
                        $expr = $item['operator'] === 'in' ? '1 = 0' : '1 = 1';
                        break;
                    }
                    $expr = $column . ($item['operator'] === 'in' ? ' IN (' : ' NOT IN (') . implode(', ', array_fill(0, count($value), '?')) . ')';
                    array_push($bindings, ...array_values($value));
                    break;
                case 'is null':
                    $expr = $column . ' IS NULL';
                    break;
                case 'is not null':
                    $expr = $column . ' IS NOT NULL';
                    break;
                case 'like':
                case 'not like':
                    $expr = $this->grammar->castToText($column) . ($item['operator'] === 'like' ? ' LIKE ?' : ' NOT LIKE ?') . $this->escapeClause();
                    $bindings[] = $value;
                    break;
                default:
                    $expr = $column . ' ' . $item['operator'] . ' ?';
                    $bindings[] = $this->bindValue($table->columns[$item['column']], $value);
            }

            $sql .= ($i === 0 ? '' : ($item['boolean'] === 'or' ? ' OR ' : ' AND ')) . $expr;
        }

        return new SqlFragment($sql, $bindings);
    }

    /**
     * Client filters from the query string.
     *
     * @param list<string> $allowedColumns Columns of the resource the client may filter on.
     */
    public function filters(FilterGroup $group, ResolvedResource $context, string $alias, array $allowedColumns): SqlFragment
    {
        $parts = [];
        $bindings = [];
        foreach ($group->items as $item) {
            $fragment = $item instanceof FilterGroup
                ? $this->filters($item, $context, $alias, $allowedColumns)
                : $this->filter($item, $context, $alias, $allowedColumns);
            if ($fragment->isEmpty()) {
                continue;
            }
            $parts[] = '(' . $fragment->sql . ')';
            array_push($bindings, ...$fragment->bindings);
        }
        if ($parts === []) {
            return new SqlFragment();
        }
        $sql = implode($group->boolean === 'or' ? ' OR ' : ' AND ', $parts);

        return new SqlFragment($group->negated ? 'NOT (' . $sql . ')' : $sql, $bindings);
    }

    /**
     * Full text style search over several columns.
     *
     * @param list<string> $columns
     */
    public function search(string $term, Table $table, string $alias, array $columns): SqlFragment
    {
        if ($columns === []) {
            return new SqlFragment();
        }
        $pattern = '%' . addcslashes($term, '%_\\') . '%';
        $parts = [];
        $bindings = [];
        foreach ($columns as $column) {
            $parts[] = $this->grammar->ilike($this->grammar->wrap($alias . '.' . $column)) . $this->escapeClause();
            $bindings[] = $pattern;
        }

        return new SqlFragment(implode(' OR ', $parts), $bindings);
    }

    /**
     * @param list<string> $allowedColumns
     */
    private function filter(Filter $filter, ResolvedResource $context, string $alias, array $allowedColumns): SqlFragment
    {
        if ($filter->relation !== null) {
            return $this->relationFilter($filter, $context, $alias);
        }

        if (! in_array($filter->column, $allowedColumns, true)) {
            throw ApiException::badRequest(sprintf('Unknown or non filterable column "%s".', $filter->column));
        }

        return $this->comparison($filter, $context->table->columns[$filter->column], $this->grammar->wrap($alias . '.' . $filter->column));
    }

    private function relationFilter(Filter $filter, ResolvedResource $context, string $alias): SqlFragment
    {
        $relation = $context->resource->getRelation((string) $filter->relation)
            ?? throw ApiException::badRequest(sprintf('Unknown relation "%s" in filter.', $filter->relation));
        if ($this->relationResolver === null) {
            throw ApiException::badRequest('Relation filters are not supported here.');
        }

        /** @var array{0: Table, 1: list<string>, 2: SqlFragment|null} $resolved */
        $resolved = ($this->relationResolver)($relation);
        [$target, $allowed, $extra] = $resolved;
        if (! in_array($filter->column, $allowed, true)) {
            throw ApiException::badRequest(sprintf('Column "%s" of relation "%s" cannot be filtered.', $filter->column, $relation->name));
        }

        $inner = $this->comparison(
            new Filter($filter->column, $filter->operator, $filter->value, $filter->negated),
            $target->columns[$filter->column],
            $this->grammar->wrap('r.' . $filter->column),
        );
        $where = SqlFragment::and($inner, $extra ?? new SqlFragment());
        $targetTable = $this->grammar->wrapTable($target->name);
        $pk = $context->requirePrimaryKey();

        switch ($relation->type) {
            case Relation::BELONGS_TO:
                $ownerKey = $relation->ownerKey ?? $target->singlePrimaryKey()
                    ?? throw new ConfigurationException('Relation "' . $relation->name . '" needs an owner key.');
                $sql = sprintf(
                    '%s IN (SELECT %s FROM %s r WHERE %s)',
                    $this->grammar->wrap($alias . '.' . $relation->field),
                    $this->grammar->wrap('r.' . $ownerKey),
                    $targetTable,
                    $where->sql,
                );
                break;

            case Relation::HAS_MANY:
                $sql = sprintf(
                    '%s IN (SELECT %s FROM %s r WHERE %s)',
                    $this->grammar->wrap($alias . '.' . ($relation->ownerKey ?? $pk)),
                    $this->grammar->wrap('r.' . $relation->foreignKey),
                    $targetTable,
                    $where->sql,
                );
                break;

            default: // many_to_many
                $selectionKey = $relation->ownerKey ?? $target->singlePrimaryKey()
                    ?? throw new ConfigurationException('Relation "' . $relation->name . '" needs a key on the selection table.');
                $sql = sprintf(
                    '%s IN (SELECT %s FROM %s j JOIN %s r ON %s = %s WHERE %s)',
                    $this->grammar->wrap($alias . '.' . $pk),
                    $this->grammar->wrap('j.' . $relation->junctionThisKey),
                    $this->grammar->wrapTable((string) $relation->junctionTable),
                    $targetTable,
                    $this->grammar->wrap('r.' . $selectionKey),
                    $this->grammar->wrap('j.' . $relation->junctionOtherKey),
                    $where->sql,
                );
        }

        return new SqlFragment($sql, $where->bindings);
    }

    private function comparison(Filter $filter, Column $column, string $wrapped): SqlFragment
    {
        $bindings = [];
        switch ($filter->operator) {
            case 'is':
                $sql = match ($filter->value) {
                    null => $wrapped . ' IS NULL',
                    true => $wrapped . ' IS TRUE',
                    default => $wrapped . ' IS FALSE',
                };
                break;
            case 'in':
                /** @var list<string> $values */
                $values = $filter->value;
                if ($values === []) {
                    $sql = '1 = 0';
                    break;
                }
                $sql = $wrapped . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
                foreach ($values as $value) {
                    $bindings[] = $this->bindValue($column, $value);
                }
                break;
            case 'like':
                $sql = $this->grammar->like($wrapped);
                $bindings[] = (string) $filter->value;
                break;
            case 'ilike':
                $sql = $this->grammar->ilike($wrapped);
                $bindings[] = (string) $filter->value;
                break;
            default:
                $operator = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$filter->operator];
                $sql = $wrapped . ' ' . $operator . ' ?';
                $bindings[] = $this->bindValue($column, $filter->value);
        }

        return new SqlFragment($filter->negated ? 'NOT (' . $sql . ')' : $sql, $bindings);
    }

    /**
     * Convert a filter value to the PHP type matching the column, rejecting values
     * the database would choke on (e.g. "abc" for an integer column).
     */
    public function bindValue(Column $column, mixed $value): mixed
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }
        $string = (string) $value;

        return match ($column->type) {
            'integer' => preg_match('/^[\-+]?\d+$/', $string)
                ? (PHP_INT_SIZE >= 8 && strlen(ltrim($string, '-+')) < 19 ? (int) $string : $string)
                : throw ApiException::badRequest(sprintf('Invalid integer value "%s" for "%s".', $string, $column->name)),
            'number' => is_numeric($string)
                ? $string
                : throw ApiException::badRequest(sprintf('Invalid numeric value "%s" for "%s".', $string, $column->name)),
            'boolean' => match (strtolower($string)) {
                'true', '1', 't', 'yes', 'on' => true,
                'false', '0', 'f', 'no', 'off' => false,
                default => throw ApiException::badRequest(sprintf('Invalid boolean value "%s" for "%s".', $string, $column->name)),
            },
            default => $string,
        };
    }

    private function escapeClause(): string
    {
        // MySQL treats backslash as an escape character inside string literals.
        return $this->grammar instanceof \GroceryRestable\Database\Grammar\MySqlGrammar ? " ESCAPE '\\\\'" : " ESCAPE '\\'";
    }
}
