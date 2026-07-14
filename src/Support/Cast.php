<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Support;

use Carbon\CarbonImmutable;

/**
 * Narrows the untyped scalars PDO hands back into the types a value object wants.
 *
 * PDO reports every column as a string on some builds and as a native scalar on
 * others, depending on the driver, the server and whether prepares are emulated.
 * Rather than let each query guess, they all coerce the same way here.
 */
final class Cast
{
    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * PostgreSQL booleans arrive as a native bool or as the letter they are
     * stored under, so both spellings have to mean the same thing.
     */
    public static function boolean(mixed $value): bool
    {
        return in_array($value, [true, 't', 'true', 'on', '1', 1], true);
    }

    /**
     * PostgreSQL leaves a timestamp null until the event has happened, which for
     * most tables in a young database means forever.
     */
    public static function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
