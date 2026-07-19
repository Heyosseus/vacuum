<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Internals\Values;

/**
 * A chain of Heap-Only Tuple updates, read off one page: the line pointer an
 * index still points at, and every heap-only tuple it was redirected
 * through to reach the row's current version.
 *
 * Rendering this as `lp 3 -> lp 7 -> lp 12 (current)` is what makes
 * fillfactor, HOT, and why indexing a frequently-updated column is
 * expensive into one picture instead of three separately-hard ideas.
 */
final readonly class HotChain
{
    /**
     * @param  list<int>  $lineNumbers  The root redirect first, then every
     *                                  heap-only tuple the chain passes
     *                                  through, in the order it is followed.
     */
    public function __construct(
        public int $rootLineNumber,
        public array $lineNumbers,
        public int $length,
    ) {}
}
