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
     * @param  bool  $lockedOnly  HEAP_XMAX_LOCK_ONLY: xmax records a transaction
     *                            that *locked* this row, not one that replaced it.
     * @param  bool  $xmaxInvalid  HEAP_XMAX_INVALID: whatever xmax holds, it is not
     *                             a valid deleter -- the transaction aborted.
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
        public bool $lockedOnly,
        public bool $xmaxInvalid,
    ) {}

    /**
     * Whether a later version of this row has genuinely replaced it.
     *
     * A non-zero xmax is not the question, though it is the one that used to be
     * asked here. PostgreSQL writes xmax for a lock as well as for a delete --
     * SELECT ... FOR UPDATE sets it and changes nothing -- and leaves it in place
     * when the deleting transaction aborts. Both cases are live, current rows,
     * and both were being drawn as superseded and awaiting vacuum. A reader
     * opening this page to learn what a dead tuple looks like was being shown one
     * that is not dead.
     */
    public function isSuperseded(): bool
    {
        if (in_array($this->xmax, [null, '', '0'], true)) {
            return false;
        }

        return ! $this->lockedOnly && ! $this->xmaxInvalid;
    }
}
