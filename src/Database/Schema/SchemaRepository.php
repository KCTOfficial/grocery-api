<?php

declare(strict_types=1);

namespace GroceryRestable\Database\Schema;

use GroceryRestable\Database\ConnectionManager;
use GroceryRestable\Store\ConfigStore;

/**
 * Cached access to the introspected database schema.
 *
 * Each request runs one cheap "fingerprint" query; the full introspection runs again only when
 * the structure changed (or the cache TTL expired).
 */
final class SchemaRepository
{
    /** @var array<string, array<string, Table>> */
    private array $memory = [];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly ConfigStore $store,
    ) {
    }

    /**
     * @return array<string, Table>
     */
    public function tables(?string $connection = null): array
    {
        $connection ??= $this->connections->defaultName();

        if (isset($this->memory[$connection])) {
            return $this->memory[$connection];
        }

        $cacheKey = 'schema:' . $connection;
        $inspector = SchemaInspector::for($this->connections->get($connection));
        $fingerprint = $inspector->fingerprint();

        // The cache is reused only while the database structure is unchanged, so new tables and
        // columns are visible on the next request; the TTL is only an upper bound.
        $cached = $this->store->cacheGet($cacheKey);
        if (is_array($cached) && isset($cached['tables']) && ($cached['fingerprint'] ?? null) === $fingerprint) {
            $tables = [];
            foreach ($cached['tables'] as $name => $table) {
                $tables[$name] = Table::fromArray($table);
            }

            return $this->memory[$connection] = $tables;
        }

        $tables = $inspector->inspect();
        $this->store->cachePut(
            $cacheKey,
            ['fingerprint' => $fingerprint, 'tables' => array_map(static fn (Table $t): array => $t->toArray(), $tables)],
            (int) $this->store->setting('schema_cache_ttl', 300),
        );

        return $this->memory[$connection] = $tables;
    }

    public function find(string $table, ?string $connection = null): ?Table
    {
        return $this->tables($connection)[$table] ?? null;
    }

    public function has(string $table, ?string $connection = null): bool
    {
        return $this->find($table, $connection) !== null;
    }

    /**
     * Foreign keys of other tables that reference $table.
     *
     * @return list<ForeignKey>
     */
    public function referencingKeys(string $table, ?string $connection = null): array
    {
        $result = [];
        foreach ($this->tables($connection) as $candidate) {
            foreach ($candidate->foreignKeys as $fk) {
                if ($fk->referencedTable === $table) {
                    $result[] = $fk;
                }
            }
        }

        return $result;
    }

    public function refresh(?string $connection = null): void
    {
        if ($connection === null) {
            $this->memory = [];
            foreach (array_keys($this->connections->all()) as $name) {
                $this->store->clearCache('schema:' . $name);
            }

            return;
        }
        unset($this->memory[$connection]);
        $this->store->clearCache('schema:' . $connection);
    }
}
