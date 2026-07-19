<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Internals\Decoders;

/**
 * Parses PostgreSQL's `(block,offset)` text form of a tuple id.
 *
 * A ctid is how one tuple points at another -- a row's current version at
 * itself, an old row version at the one that replaced it, a redirect at the
 * live tail of a HOT chain.
 */
final class Ctid
{
    /**
     * Malformed input returns block 0, offset 0 rather than throwing: the
     * caller renders whatever it got, and a page reader should never take
     * the whole panel down over one unparsable value.
     *
     * @return array{block: int, offset: int}
     */
    public static function parse(string $ctid): array
    {
        if (preg_match('/^\((\d+),(\d+)\)$/', trim($ctid), $matches) !== 1) {
            return ['block' => 0, 'offset' => 0];
        }

        return ['block' => (int) $matches[1], 'offset' => (int) $matches[2]];
    }

    /**
     * Whether a tuple's ctid points at its own line pointer -- true for a
     * row's current version, false for anything superseded by an update.
     */
    public static function pointsToSelf(string $ctid, int $block, int $lineNumber): bool
    {
        $parsed = self::parse($ctid);

        return $parsed['block'] === $block && $parsed['offset'] === $lineNumber;
    }
}
