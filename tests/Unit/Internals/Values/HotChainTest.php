<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Internals\Values\HeapPage;
use Heyosseus\Vacuum\Internals\Values\LinePointer;

/**
 * A page built from nothing but the pointers a test cares about, with
 * header fields no chain-following test looks at filled in with harmless
 * defaults.
 *
 * @param  list<LinePointer>  $pointers
 */
function pageWith(array $pointers): HeapPage
{
    return new HeapPage(
        block: 0,
        lsn: '0/0',
        lower: 32,
        upper: 8192,
        special: 8192,
        pageSize: 8192,
        pruneXid: '0',
        pointers: $pointers,
    );
}

/**
 * A line pointer with sensible defaults for every field a chain-following
 * test does not care about.
 */
function pointer(
    int $lineNumber,
    string $state = 'normal',
    ?string $ctid = null,
    bool $heapOnly = false,
    bool $hotUpdated = false,
): LinePointer {
    return new LinePointer(
        lineNumber: $lineNumber,
        offset: 0,
        state: $state,
        length: 32,
        xmin: '100',
        xmax: '0',
        ctid: $ctid,
        flags: [],
        isDead: $state === 'dead',
        isRedirect: $state === 'redirect',
        heapOnly: $heapOnly,
        hotUpdated: $hotUpdated,
    );
}

it('follows a HOT chain across a page', function (): void {
    $page = pageWith([
        pointer(1, state: 'redirect', ctid: '(0,2)'),
        pointer(2, state: 'normal', ctid: '(0,3)', heapOnly: true, hotUpdated: true),
        pointer(3, state: 'normal', ctid: '(0,3)', heapOnly: true),
    ]);

    $chains = $page->hotChains();

    expect($chains)->toHaveCount(1)
        ->and($chains[0]->rootLineNumber)->toBe(1)
        ->and($chains[0]->lineNumbers)->toBe([1, 2, 3])
        ->and($chains[0]->length)->toBe(3);
});

it('does not hang on a page whose chain points back at itself', function (): void {
    // A corrupt page must produce a wrong answer, never an infinite loop.
    $page = pageWith([
        pointer(1, state: 'redirect', ctid: '(0,2)'),
        pointer(2, state: 'normal', ctid: '(0,1)', heapOnly: true),
    ]);

    expect($page->hotChains()[0]->lineNumbers)->toBe([1, 2]);
});

it('finds no chains on a page with nothing redirected', function (): void {
    $page = pageWith([
        pointer(1),
        pointer(2),
    ]);

    expect($page->hotChains())->toBe([]);
});

it('stops a chain the moment the next tuple is an ordinary update, not a heap-only one', function (): void {
    // A redirect can lead to a tuple that itself is a fresh update rather than
    // heap-only -- the fillfactor ran out mid-chain, and the row moved to a
    // page nothing on this page's redirect array can name. The picture has to
    // stop there rather than claim a HOT chain longer than the row actually has.
    $page = pageWith([
        pointer(1, state: 'redirect', ctid: '(0,2)'),
        pointer(2, state: 'normal', ctid: '(0,2)', heapOnly: false),
    ]);

    expect($page->hotChains()[0]->lineNumbers)->toBe([1, 2]);
});

it('stops a chain the moment it steps off the page', function (): void {
    $page = pageWith([
        pointer(1, state: 'redirect', ctid: '(1,4)'),
    ]);

    expect($page->hotChains()[0]->lineNumbers)->toBe([1]);
});
