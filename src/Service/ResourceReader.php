<?php

declare(strict_types=1);

namespace GroceryRestable\Service;

use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Auth\Authenticator;
use GroceryRestable\Database\Schema\SchemaRepository;
use GroceryRestable\Database\Schema\Table;
use GroceryRestable\Exception\ApiException;
use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Query\FilterGroup;
use GroceryRestable\Query\QueryOptions;
use GroceryRestable\Query\SelectNode;
use GroceryRestable\Resource\Conditions;
use GroceryRestable\Resource\Relation;
use GroceryRestable\Resource\ResolvedResource;
use GroceryRestable\Resource\ResourceRegistry;
use GroceryRestable\Store\ConfigStore;

/**
 * Read operations: list, single record, relation embedding and relation options.
 */
final class ResourceReader
{
    private const CHUNK = 500;
    private const PARENT_ALIAS = '__gr_parent';

    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly SchemaRepository $schema,
        private readonly Authenticator $auth,
        private readonly ConfigStore $store,
        private readonly RowTransformer $transformer = new RowTransformer(),
        private readonly int $maxEmbedDepth = 3,
    ) {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int|null, limit: int, offset: int}
     */
    public function list(ResolvedResource $ctx, QueryOptions $options, AuthContext $auth): array
    {
        $resource = $ctx->resource;
        if (! $resource->isEnabled('list')) {
            throw ApiException::methodNotAllowed('Listing "' . $ctx->name() . '" is disabled.');
        }
        $this->auth->authorize($auth, $resource, 'select');
        $this->assertDepth($options->select);

        $maxLimit = min($resource->getMaxLimit() ?? PHP_INT_MAX, (int) $this->store->setting('max_limit', 1000));
        $limit = min($options->limit ?? $resource->getPerPage() ?? (int) $this->store->setting('default_per_page', 25), $maxLimit);
        $offset = $options->page !== null ? ($options->page - 1) * $limit : ($options->offset ?? 0);

        $queryable = $resource->queryableColumns($ctx->table);
        [$columns, $embeds] = $this->plan(
            $ctx,
            $options->select,
            $resource->listColumns($ctx->table),
            $queryable,
            $options->select->explicit ? [] : $resource->defaultListEmbeds(),
        );

        $where = SqlFragment::and(
            $this->rowConditions($ctx, $auth, 't0'),
            $this->compiler($ctx, $auth)->filters($options->filters, $ctx, 't0', $queryable),
            $options->search !== null
                ? $this->compiler($ctx, $auth)->search($options->search, $ctx->table, 't0', $resource->searchableColumns($ctx->table))
                : new SqlFragment(),
        );

        $orderSql = $this->orderBy($ctx, $options->order, $queryable);
        $fetch = $this->fetchColumns($ctx, $columns, $embeds);

        $rows = $ctx->db->select(
            'SELECT ' . $this->selectList($ctx, $fetch, 't0') . ' FROM ' . $ctx->wrappedTable() . ' t0'
            . $where->toWhere() . $orderSql . $ctx->grammar->limitOffset($limit, $offset),
            $where->bindings,
        );

        $total = null;
        if ($options->count || $this->store->setting('response_envelope')) {
            $total = (int) ($ctx->db->select('SELECT COUNT(*) AS c FROM ' . $ctx->wrappedTable() . ' t0' . $where->toWhere(), $where->bindings)[0]['c'] ?? 0);
        }

        return [
            'rows' => $this->output($ctx, $rows, $columns, $embeds, $auth, 0),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(ResolvedResource $ctx, int|string $id, ?SelectNode $select, AuthContext $auth): ?array
    {
        $resource = $ctx->resource;
        if (! $resource->isEnabled('read')) {
            throw ApiException::methodNotAllowed('Reading single "' . $ctx->name() . '" records is disabled.');
        }
        $this->auth->authorize($auth, $resource, 'select');
        $select ??= new SelectNode();
        $this->assertDepth($select);

        $pk = $ctx->requirePrimaryKey();
        [$columns, $embeds] = $this->plan(
            $ctx,
            $select,
            $resource->readColumns($ctx->table),
            $resource->queryableColumns($ctx->table),
            $select->explicit ? [] : $resource->defaultReadEmbeds(),
        );
        $fetch = $this->fetchColumns($ctx, $columns, $embeds);

        $where = SqlFragment::and(
            new SqlFragment($ctx->grammar->wrap('t0.' . $pk) . ' = ?', [$this->compiler($ctx, $auth)->bindValue($ctx->table->columns[$pk], $id)]),
            $this->rowConditions($ctx, $auth, 't0'),
        );
        $rows = $ctx->db->select(
            'SELECT ' . $this->selectList($ctx, $fetch, 't0') . ' FROM ' . $ctx->wrappedTable() . ' t0' . $where->toWhere() . $ctx->grammar->limitOffset(1, null),
            $where->bindings,
        );
        if ($rows === []) {
            return null;
        }

        return $this->output($ctx, $rows, $columns, $embeds, $auth, 0)[0];
    }

    /**
     * Raw row (every column) honoring the row level conditions. Used by write operations.
     *
     * @return array<string, mixed>|null
     */
    public function findRaw(ResolvedResource $ctx, int|string $id, AuthContext $auth): ?array
    {
        $pk = $ctx->requirePrimaryKey();
        $where = SqlFragment::and(
            new SqlFragment($ctx->grammar->wrap('t0.' . $pk) . ' = ?', [$this->compiler($ctx, $auth)->bindValue($ctx->table->columns[$pk], $id)]),
            $this->rowConditions($ctx, $auth, 't0'),
        );
        $rows = $ctx->db->select('SELECT t0.* FROM ' . $ctx->wrappedTable() . ' t0' . $where->toWhere() . $ctx->grammar->limitOffset(1, null), $where->bindings);

        return $rows[0] ?? null;
    }

    /**
     * Primary keys matching client filters (bulk update / delete).
     *
     * @return list<int|string>
     */
    public function matchingIds(ResolvedResource $ctx, FilterGroup $filters, AuthContext $auth, int $max): array
    {
        if ($filters->isEmpty()) {
            throw ApiException::badRequest('Bulk operations require at least one filter (e.g. ?status=eq.draft).');
        }
        $pk = $ctx->requirePrimaryKey();
        $where = SqlFragment::and(
            $this->rowConditions($ctx, $auth, 't0'),
            $this->compiler($ctx, $auth)->filters($filters, $ctx, 't0', $ctx->resource->queryableColumns($ctx->table)),
        );
        $rows = $ctx->db->select(
            'SELECT ' . $ctx->grammar->wrap('t0.' . $pk) . ' AS id FROM ' . $ctx->wrappedTable() . ' t0' . $where->toWhere()
            . $ctx->grammar->limitOffset($max + 1, null),
            $where->bindings,
        );
        if (count($rows) > $max) {
            throw ApiException::badRequest(sprintf('The filter matches more than %d rows. Narrow it down.', $max));
        }

        return array_column($rows, 'id');
    }

    /**
     * Value / label pairs for a belongs_to field or a many_to_many relation (dropdowns).
     *
     * @return list<array{value: mixed, label: string}>
     */
    public function options(ResolvedResource $ctx, string $field, ?string $search, int $limit, AuthContext $auth): array
    {
        $this->auth->authorize($auth, $ctx->resource, 'select');
        $relation = $ctx->resource->getRelationByField($field) ?? $ctx->resource->getRelation($field);
        if ($relation === null || $relation->isHasMany()) {
            throw ApiException::notFound('No belongs_to or many_to_many relation for "' . $field . '".');
        }

        $target = $this->target($ctx, $relation, $auth, true);
        $key = $target['key'];
        // Only title columns the caller could read on the target itself (never hidden / password columns).
        $titleColumns = array_values(array_filter($relation->titleColumns(), static fn (string $c): bool => in_array($c, $target['allowed'], true)));
        $columns = array_values(array_unique([$key, ...$titleColumns]));
        $where = SqlFragment::and(
            $target['extra'],
            $search !== null && $search !== '' ? $this->compiler($ctx, $auth)->search($search, $target['table'], 'r', $titleColumns) : new SqlFragment(),
        );
        $order = $relation->orderBy !== null && $target['table']->hasColumn($relation->orderBy[0])
            ? ' ORDER BY ' . $ctx->grammar->wrap('r.' . $relation->orderBy[0]) . ' ' . strtoupper($relation->orderBy[1])
            : ($titleColumns !== [] ? ' ORDER BY ' . $ctx->grammar->wrap('r.' . $titleColumns[0]) : '');

        $rows = $ctx->db->select(
            'SELECT ' . implode(', ', array_map(static fn (string $c): string => $ctx->grammar->wrap('r.' . $c), $columns))
            . ' FROM ' . $ctx->grammar->wrapTable($target['table']->name) . ' r' . $where->toWhere() . $order . $ctx->grammar->limitOffset($limit, null),
            $where->bindings,
        );

        return array_map(fn (array $row): array => [
            'value' => $this->transformer->cast($row[$key], $target['table']->columns[$key]->type, $target['table']->columns[$key]->type),
            'label' => $relation->renderTitle($row),
        ], $rows);
    }

    /**
     * The subset of $ids that exist on the other side of $relation and that $auth may read
     * (many_to_many writes must not attach rows the caller cannot see).
     *
     * @param list<mixed> $ids
     * @return list<string>
     */
    public function visibleRelatedIds(ResolvedResource $ctx, Relation $relation, array $ids, AuthContext $auth): array
    {
        if ($ids === []) {
            return [];
        }
        $target = $this->target($ctx, $relation, $auth, true);
        $column = $target['table']->columns[$target['key']];
        $compiler = $this->compiler($ctx, $auth);
        $where = SqlFragment::and(
            new SqlFragment(
                $ctx->grammar->wrap('r.' . $target['key']) . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
                array_map(static fn (mixed $id): mixed => $compiler->bindValue($column, $id), array_values($ids)),
            ),
            $target['extra'],
        );
        $rows = $ctx->db->select(
            'SELECT ' . $ctx->grammar->wrap('r.' . $target['key']) . ' AS k FROM ' . $ctx->grammar->wrapTable($target['table']->name) . ' r' . $where->toWhere(),
            $where->bindings,
        );

        return array_map(static fn (array $row): string => (string) $row['k'], $rows);
    }

    /**
     * Static where() conditions plus scope() callbacks of a resource, compiled for $alias.
     */
    public function rowConditions(ResolvedResource $ctx, AuthContext $auth, string $alias): SqlFragment
    {
        $compiler = new SqlCompiler($ctx->grammar);
        $fragments = [$compiler->conditions($ctx->resource->getWhere(), $ctx->table, $alias)];
        // Like Supabase's service_role, the service role bypasses row level security scopes.
        $scopes = $auth->isService() ? [] : $ctx->resource->getScopes();
        foreach ($scopes as $scope) {
            $conditions = Conditions::forScope();
            $scope($conditions, $auth);
            $fragments[] = $compiler->conditions($conditions, $ctx->table, $alias);
        }

        return SqlFragment::and(...$fragments);
    }

    // ------------------------------------------------------------------

    private function compiler(ResolvedResource $ctx, AuthContext $auth): SqlCompiler
    {
        return new SqlCompiler($ctx->grammar, function (Relation $relation) use ($ctx, $auth): array {
            // Filtering on a relation reveals its values, so it needs the same read permission as embedding.
            $target = $this->target($ctx, $relation, $auth, true);

            return [$target['table'], $target['allowed'], $target['extra']];
        });
    }

    /**
     * Everything needed to query the table on the other side of a relation.
     *
     * @return array{table: Table, resolved: ResolvedResource|null, allowed: list<string>, defaults: list<string>, extra: SqlFragment, key: string}
     */
    private function target(ResolvedResource $parent, Relation $relation, AuthContext $auth, bool $requireRead = false): array
    {
        $table = $this->schema->find($relation->table, $parent->connectionName)
            ?? throw new ConfigurationException(sprintf('Relation "%s" references unknown table "%s".', $relation->name, $relation->table));

        $targetResource = $this->registry->forTable($relation->table, $parent->connectionName);
        $resolved = $targetResource !== null ? $this->registry->resolve($targetResource) : null;

        $key = match ($relation->type) {
            Relation::HAS_MANY => (string) $relation->foreignKey,
            default => $relation->ownerKey
                ?? $parent->resource->getPrimaryKeyOverride($relation->table)
                ?? $targetResource?->primaryKey($table)
                ?? $table->singlePrimaryKey()
                ?? throw new ConfigurationException(sprintf('Relation "%s": table "%s" has no primary key, use setPrimaryKey($field, "%s").', $relation->name, $relation->table, $relation->table)),
        };

        $compiler = new SqlCompiler($parent->grammar);
        $extra = [$relation->where !== null ? $compiler->conditions($relation->where, $table, 'r') : new SqlFragment()];
        if ($relation->rawWhere !== null) {
            $extra[] = new SqlFragment($relation->rawWhere);
        }

        if ($resolved !== null) {
            if ($requireRead) {
                $this->auth->authorize($auth, $resolved->resource, 'select');
            }
            $allowed = $resolved->resource->queryableColumns($table);
            $defaults = $resolved->resource->listColumns($table);
            $extra[] = $this->rowConditions($resolved, $auth, 'r');
        } else {
            if ($this->registry->isWithheld($relation->table, $parent->connectionName)) {
                throw ApiException::forbidden(sprintf('Relation "%s" points to "%s", which is not exposed by the API.', $relation->name, $relation->table));
            }
            // Not exposed as a resource: only the key and the title columns are visible.
            $allowed = array_values(array_unique(array_filter(
                [$table->singlePrimaryKey(), $key, ...$relation->titleColumns()],
                static fn (?string $c): bool => $c !== null && $table->hasColumn($c),
            )));
            $defaults = $allowed;
        }

        return ['table' => $table, 'resolved' => $resolved, 'allowed' => $allowed, 'defaults' => $defaults, 'extra' => SqlFragment::and(...$extra), 'key' => $key];
    }

    /**
     * Resolve output columns and embeds of a select node.
     *
     * @param list<string> $defaults
     * @param list<string> $allowed
     * @param list<string> $defaultEmbeds
     * @return array{0: list<array{column: string, alias: string}>, 1: array<string, array{relation: string, alias: string, node: SelectNode, explicit: bool}>}
     */
    private function plan(ResolvedResource $ctx, SelectNode $node, array $defaults, array $allowed, array $defaultEmbeds): array
    {
        $columns = [];
        $embeds = [];
        $useDefaults = ! $node->explicit && $node->columns === [] && $node->embeds === [];

        if ($useDefaults || $node->star) {
            foreach ($defaults as $column) {
                $columns[$column] = ['column' => $column, 'alias' => $column];
            }
        }
        foreach ($node->columns as $item) {
            if (in_array($item['column'], $allowed, true)) {
                $columns[$item['alias']] = $item;
                continue;
            }
            // "select=title,actors" - a relation without parenthesis embeds all its columns.
            if ($ctx->resource->getRelation($item['column']) !== null) {
                $embeds[$item['alias']] = ['relation' => $item['column'], 'alias' => $item['alias'], 'node' => SelectNode::star(), 'explicit' => true];
                continue;
            }
            throw ApiException::badRequest(sprintf('Unknown column "%s" for "%s".', $item['column'], $ctx->name()));
        }
        foreach ($node->embeds as $alias => $embed) {
            if ($ctx->resource->getRelation($embed['relation']) === null) {
                throw ApiException::badRequest(sprintf('"%s" has no relation named "%s".', $ctx->name(), $embed['relation']));
            }
            $embeds[$alias] = $embed + ['explicit' => true];
        }
        foreach ($defaultEmbeds as $relation) {
            $embeds[$relation] ??= ['relation' => $relation, 'alias' => $relation, 'node' => SelectNode::star(), 'explicit' => false];
        }

        return [array_values($columns), $embeds];
    }

    /**
     * Output columns plus the key columns needed to attach embedded relations.
     *
     * @param list<array{column: string, alias: string}> $columns
     * @param array<string, array{relation: string, alias: string, node: SelectNode, explicit: bool}> $embeds
     * @return list<string>
     */
    private function fetchColumns(ResolvedResource $ctx, array $columns, array $embeds): array
    {
        $fetch = array_column($columns, 'column');
        foreach ($embeds as $embed) {
            $relation = $ctx->resource->getRelation($embed['relation']);
            $fetch[] = match (true) {
                $relation->isBelongsTo() => (string) $relation->field,
                $relation->isHasMany() => $relation->ownerKey ?? $ctx->requirePrimaryKey(),
                default => $ctx->requirePrimaryKey(),
            };
        }
        if (($pk = $ctx->primaryKey()) !== null) {
            $fetch[] = $pk;
        }

        return array_values(array_unique($fetch));
    }

    /**
     * @param list<string> $columns
     */
    private function selectList(ResolvedResource $ctx, array $columns, string $alias): string
    {
        if ($columns === []) {
            return '1 AS ' . $ctx->grammar->wrap('__gr_none');
        }

        return implode(', ', array_map(static fn (string $c): string => $ctx->grammar->wrap($alias . '.' . $c), $columns));
    }

    /**
     * @param list<array{column: string, direction: string, nulls: string|null}> $order
     * @param list<string> $allowed
     */
    private function orderBy(ResolvedResource $ctx, array $order, array $allowed): string
    {
        $parts = [];
        foreach ($order as $item) {
            if (! in_array($item['column'], $allowed, true)) {
                throw ApiException::badRequest(sprintf('Cannot order by unknown column "%s".', $item['column']));
            }
            $parts[] = $ctx->grammar->orderBy($ctx->grammar->wrap('t0.' . $item['column']), $item['direction'], $item['nulls']);
        }
        if ($parts === []) {
            foreach ($ctx->resource->getOrderBy() as [$column, $direction]) {
                if ($ctx->table->hasColumn($column)) {
                    $parts[] = $ctx->grammar->orderBy($ctx->grammar->wrap('t0.' . $column), $direction, null);
                }
            }
        }
        // A deterministic order keeps pagination stable.
        $pk = $ctx->primaryKey();
        if ($pk !== null && ! in_array($pk, array_column($order, 'column'), true)) {
            $parts[] = $ctx->grammar->wrap('t0.' . $pk) . ' ASC';
        }

        return $parts === [] ? '' : ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * Transform raw rows and attach embedded relations.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array{column: string, alias: string}> $columns
     * @param array<string, array{relation: string, alias: string, node: SelectNode, explicit: bool}> $embeds
     * @return list<array<string, mixed>>
     */
    private function output(ResolvedResource $ctx, array $rows, array $columns, array $embeds, AuthContext $auth, int $depth): array
    {
        $output = [];
        foreach ($rows as $i => $row) {
            $output[$i] = $this->transformer->transform($ctx->resource, $ctx->table, $row, $columns);
        }
        if ($rows === [] || $embeds === []) {
            return $output;
        }

        foreach ($embeds as $alias => $embed) {
            $relation = $ctx->resource->getRelation($embed['relation']);
            try {
                $target = $this->target($ctx, $relation, $auth, true);
            } catch (ApiException $e) {
                if ($embed['explicit']) {
                    throw $e;
                }
                continue; // default embeds are silently skipped when the caller cannot read them
            }
            $values = $this->embedValues($ctx, $relation, $target, $rows, $embed['node'], $auth, $depth + 1);
            foreach ($output as $i => &$item) {
                $item[$alias] = $values[$i];
            }
            unset($item);
        }

        return $output;
    }

    /**
     * Fetch embedded rows for every parent row in a single query per relation (no N+1).
     *
     * @param array{table: Table, resolved: ResolvedResource|null, allowed: list<string>, defaults: list<string>, extra: SqlFragment, key: string} $target
     * @param list<array<string, mixed>> $parents
     * @return array<int, mixed> Embedded value per parent index.
     */
    private function embedValues(ResolvedResource $parent, Relation $relation, array $target, array $parents, SelectNode $node, AuthContext $auth, int $depth): array
    {
        $grammar = $parent->grammar;
        $table = $target['table'];
        $resolved = $target['resolved'];

        if ($node->embeds !== [] && $resolved === null) {
            throw ApiException::badRequest(sprintf('Nested embedding through "%s" requires "%s" to be exposed as a resource.', $relation->name, $table->name));
        }

        // Projection of the embedded rows.
        $columns = [];
        if ($node->star || ($node->columns === [] && $node->embeds === [])) {
            foreach ($target['defaults'] as $column) {
                $columns[$column] = ['column' => $column, 'alias' => $column];
            }
        }
        foreach ($node->columns as $item) {
            if (! in_array($item['column'], $target['allowed'], true)) {
                throw ApiException::badRequest(sprintf('Unknown column "%s" in embedded "%s".', $item['column'], $relation->name));
            }
            $columns[$item['alias']] = $item;
        }
        $columns = array_values($columns);
        $nestedEmbeds = [];
        foreach ($node->embeds as $alias => $embed) {
            if ($resolved->resource->getRelation($embed['relation']) === null) {
                throw ApiException::badRequest(sprintf('"%s" has no relation named "%s".', $resolved->name(), $embed['relation']));
            }
            $nestedEmbeds[$alias] = $embed + ['explicit' => true];
        }

        // Key on the parent side.
        $parentKey = match ($relation->type) {
            Relation::BELONGS_TO => (string) $relation->field,
            Relation::HAS_MANY => $relation->ownerKey ?? $parent->requirePrimaryKey(),
            default => $parent->requirePrimaryKey(),
        };
        $keys = array_values(array_unique(array_filter(array_column($parents, $parentKey), static fn (mixed $v): bool => $v !== null)));

        $fetch = array_column($columns, 'column');
        if ($resolved !== null) {
            $fetch = array_merge($fetch, $this->fetchColumnsFor($resolved, $nestedEmbeds));
        }
        $fetch[] = $target['key'];
        $fetch = array_values(array_unique($fetch));

        $rows = [];
        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $select = implode(', ', array_map(static fn (string $c): string => $grammar->wrap('r.' . $c), $fetch));

            if ($relation->isManyToMany()) {
                $where = SqlFragment::and(new SqlFragment($grammar->wrap('j.' . $relation->junctionThisKey) . ' IN (' . $placeholders . ')', $chunk), $target['extra']);
                $order = $relation->priorityField !== null
                    ? ' ORDER BY ' . $grammar->wrap('j.' . $relation->priorityField)
                    : $this->embedOrder($grammar, $relation, $table, $target['key']);
                $sql = 'SELECT ' . $grammar->wrap('j.' . $relation->junctionThisKey) . ' AS ' . $grammar->wrap(self::PARENT_ALIAS) . ', ' . $select
                    . ' FROM ' . $grammar->wrapTable((string) $relation->junctionTable) . ' j JOIN ' . $grammar->wrapTable($table->name) . ' r ON '
                    . $grammar->wrap('r.' . $target['key']) . ' = ' . $grammar->wrap('j.' . $relation->junctionOtherKey)
                    . $where->toWhere() . $order;
            } else {
                $where = SqlFragment::and(new SqlFragment($grammar->wrap('r.' . $target['key']) . ' IN (' . $placeholders . ')', $chunk), $target['extra']);
                $sql = 'SELECT ' . $select . ' FROM ' . $grammar->wrapTable($table->name) . ' r' . $where->toWhere()
                    . ($relation->isHasMany() ? $this->embedOrder($grammar, $relation, $table, $table->singlePrimaryKey()) : '');
            }
            array_push($rows, ...$parent->db->select($sql, $where->bindings));
        }

        $outputRows = $resolved !== null
            ? $this->output($resolved, $rows, $columns, $nestedEmbeds, $auth, $depth)
            : array_map(fn (array $r): array => $this->transformer->transform(null, $table, $r, $columns), $rows);

        // Group by the key linking back to the parent.
        $grouped = [];
        foreach ($rows as $i => $row) {
            $link = $relation->isManyToMany() ? $row[self::PARENT_ALIAS] : $row[$target['key']];
            $grouped[(string) $link][] = $outputRows[$i];
        }

        $result = [];
        foreach ($parents as $i => $parentRow) {
            $value = $parentRow[$parentKey] ?? null;
            $matches = $value === null ? [] : ($grouped[(string) $value] ?? []);
            $result[$i] = $relation->isBelongsTo() ? ($matches[0] ?? null) : $matches;
        }

        return $result;
    }

    /**
     * @param array<string, array{relation: string, alias: string, node: SelectNode, explicit: bool}> $embeds
     * @return list<string>
     */
    private function fetchColumnsFor(ResolvedResource $ctx, array $embeds): array
    {
        return $this->fetchColumns($ctx, [], $embeds);
    }

    private function embedOrder(\GroceryRestable\Database\Grammar\Grammar $grammar, Relation $relation, Table $table, ?string $fallback): string
    {
        if ($relation->orderBy !== null && $table->hasColumn($relation->orderBy[0])) {
            return ' ORDER BY ' . $grammar->wrap('r.' . $relation->orderBy[0]) . ' ' . strtoupper($relation->orderBy[1]);
        }

        return $fallback !== null ? ' ORDER BY ' . $grammar->wrap('r.' . $fallback) : '';
    }

    private function assertDepth(SelectNode $select): void
    {
        if ($select->depth() > $this->maxEmbedDepth) {
            throw ApiException::badRequest('Embedding is limited to ' . $this->maxEmbedDepth . ' levels.');
        }
    }
}
