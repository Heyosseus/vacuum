<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Internals\Decoders;

/**
 * Names a page's lp_flags, the two-bit state every line pointer on an 8 kB
 * heap page carries.
 *
 * Values are verbatim from PostgreSQL's src/include/storage/itemid.h.
 */
final class LinePointerFlags
{
    private const int LP_UNUSED = 0;

    private const int LP_NORMAL = 1;

    private const int LP_REDIRECT = 2;

    private const int LP_DEAD = 3;

    /**
     * An unrecognised value returns 'unknown' rather than throwing: a
     * corrupt or future page should not take the panel down, only report
     * that it saw something it did not understand.
     */
    public static function describe(int $flags): string
    {
        return match ($flags) {
            self::LP_UNUSED => 'unused',
            self::LP_NORMAL => 'normal',
            self::LP_REDIRECT => 'redirect',
            self::LP_DEAD => 'dead',
            default => 'unknown',
        };
    }
}
