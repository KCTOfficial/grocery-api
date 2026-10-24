<?php

declare(strict_types=1);

namespace GroceryRestable\Support;

final class Size
{
    /**
     * Convert a human readable size ("20MB", "1067KB", "5000B", "1G") to bytes.
     */
    public static function toBytes(string|int|null $size): ?int
    {
        if ($size === null || $size === '') {
            return null;
        }
        if (is_int($size)) {
            return $size;
        }

        if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMGT]?)B?\s*$/i', $size, $m)) {
            throw new \InvalidArgumentException('Invalid size "' . $size . '". Use values like 20MB, 512KB or 1000B.');
        }

        $power = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4][strtoupper($m[2])];

        return (int) round((float) $m[1] * (1024 ** $power));
    }
}
