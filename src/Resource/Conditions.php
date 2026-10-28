<?php

declare(strict_types=1);

namespace GroceryRestable\Resource;

use GroceryRestable\Exception\ConfigurationException;

/**
 * A list of WHERE conditions with CodeIgniter Query Builder semantics:
 *
 *   ->where('status', 'active')        status = 'active'
 *   ->where('price >', 10)             price > 10
 *   ->where('price', '>=', 10)         price >= 10
 *   ->where(['a' => 1, 'b !=' => 2])   a = 1 AND b != 2
 *   ->where('deleted_at', null)        deleted_at IS NULL
 *   ->whereIn('id', [1, 2])            id IN (1, 2)
 *   ->like('name', 'jo', 'after')      name LIKE 'jo%'
 *   ->orWhere('vip', 1)                ... OR vip = 1
 *
 * Like CodeIgniter, conditions are combined in order ("a AND b OR c"), so AND binds tighter than OR.
 * Values are always bound as parameters; raw SQL is never accepted here.
 */
final class Conditions
{
    public const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'in', 'not in', 'is null', 'is not null', 'like', 'not like'];

    /** @var list<array{boolean: string, column: string, operator: string, value: mixed}> */
    private array $items = [];

    /** Strict conditions (row level security scopes) never turn "= null" into "IS NULL". */
    private bool $strict = false;

    /**
     * Conditions for a scope() callback: comparing with a missing value (null) matches no row.
     */
    public static function forScope(): self
    {
        $conditions = new self();
        $conditions->strict = true;

        return $conditions;
    }

    public function where(string|array $column, mixed $operatorOrValue = null, mixed $value = null): self
    {
        return $this->add('and', $column, $operatorOrValue, $value, func_num_args());
    }

    public function orWhere(string|array $column, mixed $operatorOrValue = null, mixed $value = null): self
    {
        return $this->add('or', $column, $operatorOrValue, $value, func_num_args());
    }

    /**
     * @param list<scalar> $values
     */
    public function whereIn(string $column, array $values): self
    {
        return $this->push('and', $column, 'in', array_values($values));
    }

    /**
     * @param list<scalar> $values
     */
    public function orWhereIn(string $column, array $values): self
    {
        return $this->push('or', $column, 'in', array_values($values));
    }

    /**
     * @param list<scalar> $values
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->push('and', $column, 'not in', array_values($values));
    }

    public function whereNull(string $column): self
    {
        return $this->push('and', $column, 'is null', null);
    }

    public function whereNotNull(string $column): self
    {
        return $this->push('and', $column, 'is not null', null);
    }

    /**
     * @param 'both'|'before'|'after'|'none' $side
     */
    public function like(string $column, string $match, string $side = 'both'): self
    {
        return $this->push('and', $column, 'like', self::likePattern($match, $side));
    }

    public function orLike(string $column, string $match, string $side = 'both'): self
    {
        return $this->push('or', $column, 'like', self::likePattern($match, $side));
    }

    public function notLike(string $column, string $match, string $side = 'both'): self
    {
        return $this->push('and', $column, 'not like', self::likePattern($match, $side));
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return list<array{boolean: string, column: string, operator: string, value: mixed}>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return array_values(array_unique(array_column($this->items, 'column')));
    }

    /**
     * @return list<array{boolean: string, column: string, operator: string, value: mixed}>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * Build from a stored definition. Accepts the toArray() format or a simple ['column' => value] map.
     *
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $conditions = new self();
        foreach ($data as $key => $item) {
            if (is_string($key)) {
                $conditions->where($key, $item);
                continue;
            }
            if (! is_array($item) || ! isset($item['column'])) {
                throw new ConfigurationException('Invalid condition definition.');
            }
            $conditions->push(
                ($item['boolean'] ?? 'and') === 'or' ? 'or' : 'and',
                (string) $item['column'],
                strtolower((string) ($item['operator'] ?? '=')),
                $item['value'] ?? null,
            );
        }

        return $conditions;
    }

    private function add(string $boolean, string|array $column, mixed $operatorOrValue, mixed $value, int $argc): self
    {
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $this->add($boolean, (string) $key, $val, null, 2);
            }

            return $this;
        }

        if ($argc >= 3) {
            return $this->push($boolean, trim($column), strtolower(trim((string) $operatorOrValue)), $value);
        }

        // CodeIgniter style: operator embedded in the key ("price >=").
        if (preg_match('/^\s*([A-Za-z0-9_]+)\s*(=|!=|<>|<=|>=|<|>|\s+(?:not\s+)?like|\s+is\s+(?:not\s+)?null)?\s*$/i', $column, $m)) {
            $operator = isset($m[2]) && trim($m[2]) !== '' ? strtolower(trim(preg_replace('/\s+/', ' ', $m[2]))) : '=';
            $operator = $operator === '<>' ? '!=' : $operator;
            $value = $operatorOrValue;

            if ($operator === '=' && $value === null && $this->strict) {
                // Scopes: where('owner_id', $auth->userId()) without a user must match nothing, not "IS NULL".
                return $this->push($boolean, $m[1], 'in', []);
            } elseif ($operator === '=' && $value === null) {
                $operator = 'is null';
            } elseif ($operator === '!=' && $value === null) {
                $operator = 'is not null';
            } elseif ($operator === '=' && is_array($value)) {
                $operator = 'in';
            }

            return $this->push($boolean, $m[1], $operator, $value);
        }

        throw new ConfigurationException('Invalid where() column "' . $column . '". Raw SQL is not supported; use a column name with an optional operator.');
    }

    private function push(string $boolean, string $column, string $operator, mixed $value): self
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new ConfigurationException('Invalid column name "' . $column . '" in condition.');
        }
        $operator = $operator === '<>' ? '!=' : $operator;
        if (! in_array($operator, self::OPERATORS, true)) {
            throw new ConfigurationException('Unsupported condition operator "' . $operator . '".');
        }
        if (in_array($operator, ['in', 'not in'], true) && ! is_array($value)) {
            throw new ConfigurationException('The "' . $operator . '" operator requires an array value.');
        }
        if (! in_array($operator, ['in', 'not in'], true) && ! is_scalar($value) && $value !== null) {
            throw new ConfigurationException('Condition values must be scalar.');
        }

        $this->items[] = ['boolean' => $boolean, 'column' => $column, 'operator' => $operator, 'value' => $value];

        return $this;
    }

    private static function likePattern(string $match, string $side): string
    {
        $escaped = addcslashes($match, '%_\\');

        return match ($side) {
            'before' => '%' . $escaped,
            'after' => $escaped . '%',
            'none' => $escaped,
            default => '%' . $escaped . '%',
        };
    }
}
