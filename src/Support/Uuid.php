<?php

declare(strict_types=1);

namespace GroceryRestable\Support;

/**
 * RFC 9562 UUIDs for primary keys that are not auto increment.
 *
 *   v4: random.
 *   v7: Unix time in milliseconds + random, so new rows sort after old ones (index friendly).
 */
final class Uuid
{
    /** Primary key generators a resource can use (see Resource::setIdGenerator()). */
    public const GENERATORS = ['uuid', 'uuid7'];

    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::format($bytes);
    }

    public static function v7(?int $milliseconds = null): string
    {
        $milliseconds ??= (int) floor(microtime(true) * 1000);
        $bytes = substr(pack('J', $milliseconds), 2, 6) . random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::format($bytes);
    }

    /**
     * @param 'uuid'|'uuid7' $generator
     */
    public static function generate(string $generator): string
    {
        return $generator === 'uuid7' ? self::v7() : self::v4();
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    private static function format(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
