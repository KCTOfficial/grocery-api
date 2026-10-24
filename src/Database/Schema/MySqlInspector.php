<?php

declare(strict_types=1);

namespace GroceryRestable\Database\Schema;

final class MySqlInspector extends SchemaInspector
{
    public function fingerprint(): string
    {
        $row = $this->connection->select(
            "SELECT COUNT(*) AS cols,
                    COALESCE(SUM(CRC32(CONCAT_WS(':', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA))), 0) AS hash,
                    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()) AS tbls,
                    (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()) AS fks
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()",
        )[0] ?? [];

        return implode('-', array_map('strval', array_values($row)));
    }

    public function inspect(): array
    {
        $tables = [];
        foreach ($this->connection->select(
            'SELECT TABLE_NAME AS tbl, TABLE_TYPE AS ttype, TABLE_COMMENT AS tcomment
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        ) as $row) {
            $tables[$row['tbl']] = [
                'type' => $row['ttype'] === 'VIEW' ? 'view' : 'table',
                'comment' => $row['ttype'] === 'VIEW' ? '' : (string) $row['tcomment'],
            ];
        }

        $primaryKeys = [];
        foreach ($this->connection->select(
            "SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY'
             ORDER BY TABLE_NAME, ORDINAL_POSITION",
        ) as $row) {
            $primaryKeys[$row['tbl']][] = $row['col'];
        }

        $columns = [];
        foreach ($this->connection->select(
            'SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, COLUMN_TYPE AS ctype, IS_NULLABLE AS nullable,
                    COLUMN_DEFAULT AS dflt, CHARACTER_MAXIMUM_LENGTH AS maxlen, EXTRA AS extra, COLUMN_COMMENT AS ccomment
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY TABLE_NAME, ORDINAL_POSITION',
        ) as $row) {
            $nativeType = (string) $row['ctype'];
            $extra = strtolower((string) $row['extra']);
            $enumValues = [];
            if (preg_match('/^(enum|set)\((.*)\)$/i', $nativeType, $m)) {
                $enumValues = array_map(
                    static fn (string $v): string => str_replace("''", "'", substr($v, 1, -1)),
                    self::splitQuoted($m[2]),
                );
            }
            $type = Column::normalizeType($nativeType);
            if (str_starts_with(strtolower($nativeType), 'set(')) {
                $type = 'string';
            }

            $columns[$row['tbl']][$row['col']] = new Column(
                name: $row['col'],
                nativeType: $nativeType,
                type: $type,
                nullable: $row['nullable'] === 'YES',
                default: self::normalizeDefault($row['dflt']),
                maxLength: $row['maxlen'] !== null && (int) $row['maxlen'] < 65536 ? (int) $row['maxlen'] : null,
                primary: in_array($row['col'], $primaryKeys[$row['tbl']] ?? [], true),
                autoIncrement: str_contains($extra, 'auto_increment'),
                enumValues: $enumValues,
                comment: (string) $row['ccomment'],
                generated: str_contains($extra, 'generated') && ! str_contains($extra, 'default_generated'),
            );
        }

        $foreignKeys = [];
        $grouped = [];
        foreach ($this->connection->select(
            'SELECT CONSTRAINT_NAME AS name, TABLE_NAME AS tbl, COLUMN_NAME AS col,
                    REFERENCED_TABLE_NAME AS ref_tbl, REFERENCED_COLUMN_NAME AS ref_col
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
        ) as $row) {
            $key = $row['tbl'] . "\0" . $row['name'];
            $grouped[$key]['name'] = $row['name'];
            $grouped[$key]['table'] = $row['tbl'];
            $grouped[$key]['ref'] = $row['ref_tbl'];
            $grouped[$key]['cols'][] = $row['col'];
            $grouped[$key]['refCols'][] = $row['ref_col'];
        }
        foreach ($grouped as $fk) {
            $foreignKeys[$fk['table']][] = new ForeignKey($fk['name'], $fk['table'], $fk['cols'], $fk['ref'], $fk['refCols']);
        }

        return $this->assemble($tables, $columns, $primaryKeys, $foreignKeys);
    }

    /**
     * Split "'a','b,c','d''e'" into raw quoted tokens.
     *
     * @return list<string>
     */
    private static function splitQuoted(string $list): array
    {
        preg_match_all("/'(?:[^']|'')*'/", $list, $m);

        return $m[0];
    }

    private static function normalizeDefault(mixed $default): mixed
    {
        if ($default === null || $default === 'NULL') {
            return null;
        }
        // MariaDB returns quoted literals.
        if (is_string($default) && strlen($default) >= 2 && $default[0] === "'" && str_ends_with($default, "'")) {
            return str_replace("''", "'", substr($default, 1, -1));
        }

        return $default;
    }
}
