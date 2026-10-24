<?php

declare(strict_types=1);

namespace GroceryRestable\Database\Schema;

final class SqliteInspector extends SchemaInspector
{
    public function fingerprint(): string
    {
        // Incremented by SQLite on every schema change.
        $row = $this->connection->select('PRAGMA schema_version')[0] ?? [];

        return (string) (reset($row) ?: '0');
    }

    public function inspect(): array
    {
        $tables = [];
        $sqlByTable = [];
        foreach ($this->connection->select(
            "SELECT name, type, sql FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'",
        ) as $row) {
            $tables[$row['name']] = ['type' => $row['type'], 'comment' => ''];
            $sqlByTable[$row['name']] = (string) $row['sql'];
        }

        $columns = [];
        $primaryKeys = [];
        $foreignKeys = [];

        foreach (array_keys($tables) as $table) {
            $info = $this->connection->select('SELECT * FROM pragma_table_xinfo(?)', [$table]);

            $pkColumns = [];
            foreach ($info as $row) {
                if ((int) $row['pk'] > 0) {
                    $pkColumns[(int) $row['pk']] = $row['name'];
                }
            }
            ksort($pkColumns);
            $primaryKeys[$table] = array_values($pkColumns);

            foreach ($info as $row) {
                if ((int) ($row['hidden'] ?? 0) === 1) {
                    continue; // hidden columns of virtual tables
                }
                $native = (string) $row['type'];
                // An INTEGER PRIMARY KEY is an alias of the rowid and therefore auto generated.
                $isRowId = count($pkColumns) === 1 && (int) $row['pk'] === 1 && strtoupper($native) === 'INTEGER';

                $columns[$table][$row['name']] = new Column(
                    name: $row['name'],
                    nativeType: $native,
                    type: $native === '' ? 'string' : Column::normalizeType($native),
                    nullable: (int) $row['notnull'] === 0 && ! $isRowId,
                    default: $row['dflt_value'] !== null ? trim((string) $row['dflt_value'], "'") : null,
                    maxLength: self::lengthFromType($native),
                    primary: (int) $row['pk'] > 0,
                    autoIncrement: $isRowId || (bool) preg_match('/\bAUTOINCREMENT\b/i', $sqlByTable[$table]) && (int) $row['pk'] === 1,
                    generated: in_array((int) ($row['hidden'] ?? 0), [2, 3], true),
                );
            }

            if ($tables[$table]['type'] !== 'table') {
                continue;
            }

            $grouped = [];
            foreach ($this->connection->select('SELECT * FROM pragma_foreign_key_list(?)', [$table]) as $row) {
                $grouped[(int) $row['id']]['ref'] = $row['table'];
                $grouped[(int) $row['id']]['cols'][] = $row['from'];
                $grouped[(int) $row['id']]['refCols'][] = $row['to'];
            }
            foreach ($grouped as $id => $fk) {
                $foreignKeys[$table][] = [
                    'name' => $table . '_fk_' . $id,
                    'ref' => $fk['ref'],
                    'cols' => $fk['cols'],
                    'refCols' => $fk['refCols'],
                ];
            }
        }

        // A NULL target column refers to the primary key of the referenced table.
        $resolved = [];
        foreach ($foreignKeys as $table => $list) {
            foreach ($list as $fk) {
                $refCols = $fk['refCols'];
                if (in_array(null, $refCols, true)) {
                    $refCols = $primaryKeys[$fk['ref']] ?? $refCols;
                }
                $resolved[$table][] = new ForeignKey($fk['name'], $table, $fk['cols'], $fk['ref'], array_map('strval', $refCols));
            }
        }

        return $this->assemble($tables, $columns, $primaryKeys, $resolved);
    }
}
