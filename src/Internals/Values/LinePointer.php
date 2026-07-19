<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Internals\Values;

/**
 * One entry in a heap page's line pointer array -- the small, fixed-size
 * slot that says where a tuple's bytes start on the page, or that it has
 * moved, or that it is gone.
 *
 * A line pointer's own state (unused, normal, redirect, dead) is read
 * separately from the tuple header it may or may not point at: a redirect
 * or an unused slot has no tuple to describe, which is why xmin, xmax and
 * ctid are nullable here even though RowVersion's are not.
 */
final readonly class LinePointer
{
    /**
     * @param  list<string>  $flags
     */
    public function __construct(
        public int $lineNumber,
        public int $offset,
        public string $state,
        public int $length,
        public ?string $xmin,
        public ?string $xmax,
        public ?string $ctid,
        public array $flags,
        public bool $isDead,
        public bool $isRedirect,
        public bool $heapOnly,
        public bool $hotUpdated,
    ) {}
}
