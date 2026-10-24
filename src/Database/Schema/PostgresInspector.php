<?php

declare(strict_types=1);

namespace GroceryRestable\Database\Schema;

final class PostgresInspector extends SchemaInspector
{
    public function fingerprint(): string
    {
        $row = $this->connection->select(
            "SELECT md5(COALESCE(string_agg(table_name || '.' || column_name || ':' || data_type || ':' || is_nullable, ',' ORDER BY table_name, ordinal_position), '')) AS cols,
                    (SELECT COUNT(*) FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
                      WHERE n.nspname = current_schema() AND c.contype IN ('f', 'p')) AS keys
             FROM information_schema.columns WHERE table_schema = current_schema()",
        )[0] ?? [];

        return implode('-', array_map('strval', array_values($row)));
    }

    public function inspect(): array
    {
        $tables = [];
        foreach ($this->connection->select(
            "SELECT c.relname AS tbl, c.relkind AS kind, COALESCE(obj_description(c.oid, 'pg_class'), '') AS tcomment
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'v', 'm', 'p')",
        ) as $row) {
            $tables[$row['tbl']] = [
                'type' => in_array($row['kind'], ['v', 'm'], true) ? 'view' : 'table',
                'comment' => (string) $row['tcomment'],
            ];
        }

        $enums = [];
        foreach ($this->connection->select(
            'SELECT t.typname AS typ, e.enumlabel AS label FROM pg_type t
             JOIN pg_enum e ON e.enumtypid = t.oid ORDER BY t.typname, e.enumsortorder',
        ) as $row) {
            $enums[$row['typ']][] = $row['label'];
        }

        $primaryKeys = [];
        foreach ($this->connection->select(
            "SELECT tc.table_name AS tbl, kcu.column_name AS col
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema AND tc.table_name = kcu.table_name
             WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = current_schema()
             ORDER BY tc.table_name, kcu.ordinal_position",
        ) as $row) {
            $primaryKeys[$row['tbl']][] = $row['col'];
        }

        $columns = [];
        foreach ($this->connection->select(
            "SELECT c.table_name AS tbl, c.column_name AS col, c.data_type AS dtype, c.udt_name AS udt,
                    c.is_nullable AS nullable, c.column_default AS dflt, c.character_maximum_length AS maxlen,
                    c.is_identity AS ident, c.is_generated AS gen,
                    COALESCE(col_description(format('%I.%I', c.table_schema, c.table_name)::regclass, c.ordinal_position::int), '') AS ccomment
             FROM information_schema.columns c
             WHERE c.table_schema = current_schema()
             ORDER BY c.table_name, c.ordinal_position",
        ) as $row) {
            $dataType = (string) $row['dtype'];
            $native = match ($dataType) {
                'USER-DEFINED' => (string) $row['udt'],
                'ARRAY' => (string) $row['udt'],
                default => $dataType,
            };
            $enumValues = $dataType === 'USER-DEFINED' ? ($enums[$row['udt']] ?? []) : [];
            $type = $enumValues !== [] ? 'enum' : ($dataType === 'ARRAY' ? 'string' : Column::normalizeType($native));
            $default = $row['dflt'];

            $columns[$row['tbl']][$row['col']] = new Column(
                name: $row['col'],
                nativeType: $native,
                type: $type,
                nullable: $row['nullable'] === 'YES',
                default: $default,
                maxLength: $row['maxlen'] !== null ? (int) $row['maxlen'] : null,
                primary: in_array($row['col'], $primaryKeys[$row['tbl']] ?? [], true),
                autoIncrement: $row['ident'] === 'YES' || (is_string($default) && str_starts_with($default, 'nextval(')),
                enumValues: $enumValues,
                comment: (string) $row['ccomment'],
                generated: $row['gen'] === 'ALWAYS',
            );
        }

        $grouped = [];
        foreach ($this->connection->select(
            "SELECT con.conname AS name, cl.relname AS tbl, att.attname AS col, fcl.relname AS ref_tbl, fatt.attname AS ref_col
             FROM pg_constraint con
             JOIN pg_class cl ON cl.oid = con.conrelid
             JOIN pg_namespace ns ON ns.oid = cl.relnamespace
             JOIN pg_class fcl ON fcl.oid = con.confrelid
             CROSS JOIN LATERAL unnest(con.conkey, con.confkey) WITH ORDINALITY AS u(attnum, fattnum, ord)
             JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = u.attnum
             JOIN pg_attribute fatt ON fatt.attrelid = con.confrelid AND fatt.attnum = u.fattnum
             WHERE con.contype = 'f' AND ns.nspname = current_schema()
             ORDER BY cl.relname, con.conname, u.ord",
        ) as $row) {
            $key = $row['tbl'] . "\0" . $row['name'];
            $grouped[$key]['name'] = $row['name'];
            $grouped[$key]['table'] = $row['tbl'];
            $grouped[$key]['ref'] = $row['ref_tbl'];
            $grouped[$key]['cols'][] = $row['col'];
            $grouped[$key]['refCols'][] = $row['ref_col'];
        }
        $foreignKeys = [];
        foreach ($grouped as $fk) {
            $foreignKeys[$fk['table']][] = new ForeignKey($fk['name'], $fk['table'], $fk['cols'], $fk['ref'], $fk['refCols']);
        }

        return $this->assemble($tables, $columns, $primaryKeys, $foreignKeys);
    }
}
