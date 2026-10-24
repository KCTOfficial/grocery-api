<?php

declare(strict_types=1);

namespace GroceryRestable\Query;

use GroceryRestable\Exception\ApiException;

/**
 * Parses PostgREST compatible query strings:
 *
 *   ?select=id,name,office:offices(city,country),orders(*,orderdetails(*))
 *   ?age=gte.18&status=in.(active,pending)&name=ilike.*john*&deleted_at=is.null
 *   ?or=(age.lt.18,age.gt.65)&not.and=(a.eq.1,b.eq.2)
 *   ?offices.city=eq.Paris            (filter on a related table)
 *   ?order=lastName.desc.nullslast,firstName
 *   ?limit=25&offset=50   or   ?page=3&per_page=25
 *   ?q=search text&count=exact&format=csv
 */
final class QueryParser
{
    public const RESERVED = ['_', 'select', 'order', 'limit', 'offset', 'page', 'per_page', 'q', 'count', 'format', 'apikey', 'columns', 'on_conflict', '_method'];

    /**
     * Split a raw query string into ordered [key, value] pairs.
     * The raw string is used (instead of $_GET) so repeated keys and dots in keys survive.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function pairs(string $queryString): array
    {
        $pairs = [];
        foreach (explode('&', ltrim($queryString, '?')) as $part) {
            if ($part === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $pairs[] = [urldecode($key), urldecode($value)];
        }

        return $pairs;
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     * @param array<string, string> $prefer Parsed "Prefer" header.
     */
    public function parse(array $pairs, array $prefer = []): QueryOptions
    {
        $options = new QueryOptions();
        $page = null;
        $perPage = null;

        foreach ($pairs as [$key, $value]) {
            switch ($key) {
                case 'select':
                    $options->select = $this->parseSelect($value);
                    $options->select->explicit = true;
                    break;
                case 'order':
                    $options->order = $this->parseOrder($value);
                    break;
                case 'limit':
                    $options->limit = $this->parseInt($value, 'limit');
                    break;
                case 'offset':
                    $options->offset = $this->parseInt($value, 'offset');
                    break;
                case 'page':
                    $page = max(1, $this->parseInt($value, 'page'));
                    break;
                case 'per_page':
                    $perPage = max(1, $this->parseInt($value, 'per_page'));
                    break;
                case 'q':
                    $options->search = trim($value) !== '' ? $value : null;
                    break;
                case 'count':
                    $options->count = in_array(strtolower($value), ['exact', 'true', '1'], true);
                    break;
                case 'format':
                    $options->format = strtolower($value) === 'csv' ? 'csv' : 'json';
                    break;
                case '_':
                case 'apikey':
                case 'columns':
                case 'on_conflict':
                case '_method':
                    break;
                case 'or':
                case 'and':
                case 'not.or':
                case 'not.and':
                    $negated = str_starts_with($key, 'not.');
                    $options->filters->add($this->parseLogic(substr($key, $negated ? 4 : 0), $value, $negated));
                    break;
                default:
                    $options->filters->add($this->parseFilter($key, $value));
            }
        }

        if ($perPage !== null) {
            $options->limit = $perPage;
        }
        // The offset of "page" is resolved by the service once the effective page size is known.
        $options->page = $page;
        if (($prefer['count'] ?? null) === 'exact') {
            $options->count = true;
        }

        return $options;
    }

    /**
     * Parse a "Prefer" header: "return=representation, count=exact".
     *
     * @return array<string, string>
     */
    public static function parsePrefer(?string $header): array
    {
        $result = [];
        foreach (explode(',', (string) $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $result[strtolower(trim($k))] = strtolower(trim($v));
        }

        return $result;
    }

    public function parseSelect(string $value): SelectNode
    {
        $node = new SelectNode();
        $value = trim($value);
        if ($value === '' || $value === '*') {
            $node->star = true;

            return $node;
        }

        foreach ($this->splitTopLevel($value) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if ($item === '*') {
                $node->star = true;
                continue;
            }

            $alias = null;
            // "alias:column" / "alias:relation(...)" — the colon must come before any parenthesis.
            $colon = strpos($item, ':');
            $paren = strpos($item, '(');
            if ($colon !== false && ($paren === false || $colon < $paren)) {
                $alias = trim(substr($item, 0, $colon));
                $item = trim(substr($item, $colon + 1));
                $paren = strpos($item, '(');
            }

            if ($paren !== false) {
                if (! str_ends_with($item, ')')) {
                    throw ApiException::badRequest('Invalid select: unbalanced parenthesis in "' . $item . '".');
                }
                $relation = trim(substr($item, 0, $paren));
                // Ignore PostgREST hints such as "relation!inner" / "relation!fk_name".
                $relation = explode('!', $relation)[0];
                $this->assertIdentifier($relation, 'relation');
                if ($alias !== null) {
                    $this->assertIdentifier($alias, 'alias');
                }
                $node->addEmbed($relation, $alias, $this->parseSelect(substr($item, $paren + 1, -1)));
                continue;
            }

            $this->assertIdentifier($item, 'column');
            if ($alias !== null) {
                $this->assertIdentifier($alias, 'alias');
            }
            $node->columns[] = ['column' => $item, 'alias' => $alias ?? $item];
        }

        return $node;
    }

    /**
     * @return list<array{column: string, direction: string, nulls: string|null}>
     */
    public function parseOrder(string $value): array
    {
        $order = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $parts = explode('.', $item);
            $column = array_shift($parts);
            $this->assertIdentifier($column, 'order column');
            $direction = 'asc';
            $nulls = null;
            foreach ($parts as $part) {
                $part = strtolower($part);
                match ($part) {
                    'asc', 'desc' => $direction = $part,
                    'nullsfirst' => $nulls = 'first',
                    'nullslast' => $nulls = 'last',
                    default => throw ApiException::badRequest('Invalid order modifier "' . $part . '".'),
                };
            }
            $order[] = ['column' => $column, 'direction' => $direction, 'nulls' => $nulls];
        }

        return $order;
    }

    public function parseFilter(string $key, string $value): Filter
    {
        $relation = null;
        $column = $key;
        if (str_contains($key, '.')) {
            [$relation, $column] = explode('.', $key, 2);
            $this->assertIdentifier($relation, 'relation');
        }
        $this->assertIdentifier($column, 'filter column');

        [$negated, $operator, $operand] = $this->parseOperation($value, $key);

        return new Filter($column, $operator, $operand, $negated, $relation);
    }

    public function parseLogic(string $boolean, string $value, bool $negated = false): FilterGroup
    {
        $value = trim($value);
        if (! str_starts_with($value, '(') || ! str_ends_with($value, ')')) {
            throw ApiException::badRequest('Logical operators expect a parenthesized list, e.g. or=(a.eq.1,b.eq.2).');
        }

        $group = new FilterGroup($boolean, [], $negated);
        foreach ($this->splitTopLevel(substr($value, 1, -1)) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            // Nested group: and(...), or(...), not.and(...), not.or(...)
            if (preg_match('/^(not\.)?(and|or)(\(.*\))$/s', $item, $m)) {
                $group->add($this->parseLogic($m[2], $m[3], $m[1] !== ''));
                continue;
            }

            $tokens = explode('.', $item);
            if (count($tokens) < 3) {
                throw ApiException::badRequest('Invalid logical filter "' . $item . '".');
            }
            $isOperation = static fn (string $t): bool => $t === 'not' || in_array($t, Filter::OPERATORS, true);
            if ($isOperation($tokens[1])) {
                $key = $tokens[0];
                $rest = implode('.', array_slice($tokens, 1));
            } elseif (count($tokens) >= 4 && $isOperation($tokens[2])) {
                $key = $tokens[0] . '.' . $tokens[1];
                $rest = implode('.', array_slice($tokens, 2));
            } else {
                throw ApiException::badRequest('Invalid logical filter "' . $item . '".');
            }
            $group->add($this->parseFilter($key, $rest));
        }

        if ($group->isEmpty()) {
            throw ApiException::badRequest('Empty logical filter.');
        }

        return $group;
    }

    /**
     * @return array{0: bool, 1: string, 2: mixed}
     */
    private function parseOperation(string $value, string $key): array
    {
        $negated = false;
        if (str_starts_with($value, 'not.')) {
            $negated = true;
            $value = substr($value, 4);
        }

        $dot = strpos($value, '.');
        if ($dot === false) {
            throw ApiException::badRequest(sprintf('Invalid filter "%s=%s". Expected operator.value, e.g. %s=eq.10', $key, $value, $key));
        }
        $operator = strtolower(substr($value, 0, $dot));
        $operand = substr($value, $dot + 1);

        if (! in_array($operator, Filter::OPERATORS, true)) {
            throw ApiException::badRequest(sprintf('Unknown filter operator "%s". Supported: %s', $operator, implode(', ', Filter::OPERATORS)));
        }

        $operand = match ($operator) {
            'in' => $this->parseList($operand),
            'is' => match (strtolower($operand)) {
                'null', 'unknown' => null,
                'true' => true,
                'false' => false,
                default => throw ApiException::badRequest('The "is" operator accepts null, true or false.'),
            },
            'like', 'ilike' => str_replace('*', '%', $operand),
            default => self::unquote($operand),
        };

        return [$negated, $operator, $operand];
    }

    /**
     * @return list<string>
     */
    private function parseList(string $operand): array
    {
        $operand = trim($operand);
        if (! str_starts_with($operand, '(') || ! str_ends_with($operand, ')')) {
            throw ApiException::badRequest('The "in" operator expects a list: in.(1,2,3)');
        }
        $inner = substr($operand, 1, -1);
        if (trim($inner) === '') {
            return [];
        }

        return array_map(static fn (string $v): string => self::unquote(trim($v)), $this->splitTopLevel($inner));
    }

    /**
     * Split on commas that are not inside parentheses or double quotes.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $value): array
    {
        $parts = [];
        $depth = 0;
        $quoted = false;
        $current = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '\\' && $quoted && $i + 1 < $length) {
                $current .= $char . $value[++$i];
                continue;
            }
            if ($char === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && $char === '(') {
                $depth++;
            } elseif (! $quoted && $char === ')') {
                $depth--;
                if ($depth < 0) {
                    throw ApiException::badRequest('Unbalanced parenthesis in query.');
                }
            } elseif (! $quoted && $depth === 0 && $char === ',') {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if ($depth !== 0 || $quoted) {
            throw ApiException::badRequest('Unbalanced parenthesis or quote in query.');
        }
        $parts[] = $current;

        return $parts;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    private function parseInt(string $value, string $name): int
    {
        if (! preg_match('/^\d{1,9}$/', $value)) {
            throw ApiException::badRequest('"' . $name . '" must be a non-negative integer.');
        }

        return (int) $value;
    }

    private function assertIdentifier(string $value, string $what): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/', $value)) {
            throw ApiException::badRequest(sprintf('Invalid %s name "%s".', $what, $value));
        }
    }
}
