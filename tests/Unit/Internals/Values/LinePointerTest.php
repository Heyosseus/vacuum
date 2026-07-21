<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Internals\Values\LinePointer;

/**
 * A line pointer carrying one particular xmax situation, with everything else a
 * plain live tuple.
 */
function pointerWithXmax(?string $xmax, bool $lockedOnly = false, bool $xmaxInvalid = false): LinePointer
{
    return new LinePointer(
        lineNumber: 1,
        offset: 8_160,
        state: 'normal',
        length: 32,
        xmin: '1000',
        xmax: $xmax,
        ctid: '(0,1)',
        flags: [],
        isDead: false,
        isRedirect: false,
        heapOnly: false,
        hotUpdated: false,
        lockedOnly: $lockedOnly,
        xmaxInvalid: $xmaxInvalid,
    );
}

it('calls a row with no xmax exactly what it is', function (): void {
    expect(pointerWithXmax('0')->isSuperseded())->toBeFalse()
        ->and(pointerWithXmax(null)->isSuperseded())->toBeFalse()
        ->and(pointerWithXmax('')->isSuperseded())->toBeFalse();
});

it('calls a genuinely replaced row superseded', function (): void {
    expect(pointerWithXmax('2000')->isSuperseded())->toBeTrue();
});

it('does not call a row somebody is holding a lock on dead', function (): void {
    // Open BEGIN; SELECT ... FOR UPDATE in another session and the row acquires
    // an xmax while changing nothing at all. Every place that read "xmax is not
    // zero" as "superseded" drew that row as awaiting vacuum, and the page's own
    // teaching copy stated it outright -- to a reader who had opened the page
    // specifically to learn what a dead tuple looks like.
    expect(pointerWithXmax('2000', lockedOnly: true)->isSuperseded())->toBeFalse();
});

it('does not call a row dead because a transaction that aborted tried to delete it', function (): void {
    // HEAP_XMAX_INVALID: xmax is there, and it is not a valid deleter. The row is
    // current, and it will stay current.
    expect(pointerWithXmax('2000', xmaxInvalid: true)->isSuperseded())->toBeFalse();
});
