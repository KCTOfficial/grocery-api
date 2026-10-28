<?php

declare(strict_types=1);

namespace GroceryRestable\Resource;

use GroceryRestable\Database\Schema\ForeignKey;
use GroceryRestable\Database\Schema\Table;

/**
 * Builds a resource definition straight from the database schema:
 * belongs_to relations from foreign keys, has_many from reverse foreign keys and
 * many_to_many from junction tables. Used for "auto expose" and as the starting
 * point of new resources in the admin GUI.
 */
final class AutoResourceFactory
{
    /** Columns never guessed as a relation title (they could reveal secrets through embedding). */
    private const SENSITIVE_COLUMN = '/pass|secret|token|hash|salt|key|otp|pin/i';
    private const TITLE_CANDIDATES = ['name', 'title', 'label', 'fullname', 'full_name', 'username', 'email', 'code', 'subject', 'description'];

    /**
     * @param array<string, Table> $tables All tables of the connection.
     */
    public function make(Table $table, array $tables, ?string $connection = null): Resource
    {
        $resource = new Resource($table->name, $table->name);
        $resource->setConnection($connection)->setSource('auto');

        if ($table->isView()) {
            $resource->unsetOperations();
        }

        $used = array_flip($table->columnNames());

        foreach ($table->foreignKeys as $fk) {
            if (! $fk->isSingleColumn() || ! isset($tables[$fk->referencedTable])) {
                continue;
            }
            $field = $fk->columns[0];
            $name = $this->uniqueName([$fk->referencedTable, (string) preg_replace('/(_id|Id|_fk)$/', '', $field), $field . '_rel'], $used);
            $used[$name] = true;
            $resource->addRelation(new Relation(
                type: Relation::BELONGS_TO,
                name: $name,
                table: $fk->referencedTable,
                field: $field,
                ownerKey: $fk->referencedColumns[0],
                titleField: self::guessTitle($tables[$fk->referencedTable]),
            ));
        }

        $pk = $table->singlePrimaryKey();
        if ($pk === null) {
            return $resource;
        }

        foreach ($tables as $child) {
            foreach ($child->foreignKeys as $fk) {
                if (! $fk->isSingleColumn() || $fk->referencedTable !== $table->name) {
                    continue;
                }

                // many_to_many through a junction table
                $other = $this->junctionPartner($child, $fk, $tables);
                if ($other !== null) {
                    [$otherFk, $otherTable] = $other;
                    $name = $this->uniqueName([$otherTable->name, $otherTable->name . '_via_' . $child->name], $used);
                    $used[$name] = true;
                    $resource->addRelation(new Relation(
                        type: Relation::MANY_TO_MANY,
                        name: $name,
                        table: $otherTable->name,
                        ownerKey: $otherFk->referencedColumns[0],
                        junctionTable: $child->name,
                        junctionThisKey: $fk->columns[0],
                        junctionOtherKey: $otherFk->columns[0],
                        titleField: self::guessTitle($otherTable),
                    ));
                }

                $name = $this->uniqueName([$child->name, $child->name . '_by_' . $fk->columns[0]], $used);
                $used[$name] = true;
                $resource->addRelation(new Relation(
                    type: Relation::HAS_MANY,
                    name: $name,
                    table: $child->name,
                    foreignKey: $fk->columns[0],
                    ownerKey: $fk->referencedColumns[0] !== $pk ? $fk->referencedColumns[0] : null,
                    titleField: self::guessTitle($child),
                ));
            }
        }

        return $resource;
    }

    /**
     * Best guess of a human readable column.
     */
    public static function guessTitle(Table $table): string
    {
        $byLower = [];
        foreach ($table->columns as $name => $column) {
            $byLower[strtolower($name)] = $name;
        }
        foreach (self::TITLE_CANDIDATES as $candidate) {
            if (isset($byLower[$candidate])) {
                return $byLower[$candidate];
            }
        }
        // Otherwise the first text column, but never one that looks like a secret.
        foreach ($table->columns as $name => $column) {
            if (! $column->primary && $column->type === 'string' && ! preg_match(self::SENSITIVE_COLUMN, $name)) {
                return $name;
            }
        }

        return $table->singlePrimaryKey() ?? (string) array_key_first($table->columns);
    }

    /**
     * When $junction looks like a pure junction table (two foreign keys making up the key),
     * return the other side.
     *
     * @param array<string, Table> $tables
     * @return array{0: ForeignKey, 1: Table}|null
     */
    private function junctionPartner(Table $junction, ForeignKey $fk, array $tables): ?array
    {
        $singleFks = array_values(array_filter($junction->foreignKeys, static fn (ForeignKey $f): bool => $f->isSingleColumn()));
        if (count($singleFks) !== 2) {
            return null;
        }
        $keyColumns = [$singleFks[0]->columns[0], $singleFks[1]->columns[0]];
        $pk = $junction->primaryKey;
        sort($pk);
        sort($keyColumns);
        $isPureKey = $pk === $keyColumns;
        // Accept junction tables with a surrogate id and a few extra columns (priority, timestamps).
        $isSmall = count($junction->columns) <= 5;
        if (! $isPureKey && ! $isSmall) {
            return null;
        }

        $otherFk = $singleFks[0]->columns[0] === $fk->columns[0] ? $singleFks[1] : $singleFks[0];
        if ($otherFk->columns[0] === $fk->columns[0] || ! isset($tables[$otherFk->referencedTable])) {
            return null;
        }

        return [$otherFk, $tables[$otherFk->referencedTable]];
    }

    /**
     * @param list<string> $candidates
     * @param array<string, mixed> $used
     */
    private function uniqueName(array $candidates, array $used): string
    {
        foreach ($candidates as $candidate) {
            $candidate = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $candidate);
            if ($candidate !== '' && ! isset($used[$candidate]) && preg_match('/^[A-Za-z_]/', $candidate)) {
                return $candidate;
            }
        }
        $base = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $candidates[0]);
        $i = 2;
        while (isset($used[$base . '_' . $i])) {
            $i++;
        }

        return $base . '_' . $i;
    }
}
