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
        lockedOnly: false,
        xmaxInvalid: false,
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

it('finds a HOT chain that no vacuum has pruned yet', function (): void {
    // The state the explorer was blind to, and it is the ordinary one. A HOT
    // chain gets its LP_REDIRECT root only when the page is *pruned*; before
    // that the root is an ordinary LP_NORMAL tuple with HEAP_HOT_UPDATED set and
    // its t_ctid pointing at the replacement. Rooting chains only at redirects
    // therefore found them exactly when a vacuum had already been through, and
    // reported "no HOT chains on this page" for a live, actively-updated table
    // between autovacuums -- precisely the table somebody tuning fillfactor came
    // here to look at.
    $page = pageWith([
        pointer(1, ctid: '(0,2)', hotUpdated: true),
        pointer(2, ctid: '(0,3)', heapOnly: true, hotUpdated: true),
        pointer(3, ctid: '(0,3)', heapOnly: true),
    ]);

    $chains = $page->hotChains();

    expect($chains)->toHaveCount(1)
        ->and($chains[0]->rootLineNumber)->toBe(1)
        ->and($chains[0]->lineNumbers)->toBe([1, 2, 3])
        ->and($chains[0]->length)->toBe(3);
});

it('does not report a tuple in the middle of a chain as a chain of its own', function (): void {
    // Line 2 is itself hotUpdated -- every link but the last one is -- and would
    // root a second, shorter chain covering ground the first already walked.
    // Membership is tracked across the page so it cannot.
    $page = pageWith([
        pointer(1, state: 'redirect', ctid: '(0,2)'),
        pointer(2, ctid: '(0,3)', heapOnly: true, hotUpdated: true),
        pointer(3, ctid: '(0,3)', heapOnly: true),
    ]);

    expect($page->hotChains())->toHaveCount(1);
});

it('does not root a chain at an updated tuple that is itself heap-only', function (): void {
    // heapOnly means it is a later version of some row, not the original, so it
    // is somebody else's chain even when this page cannot see the root.
    $page = pageWith([
        pointer(1, ctid: '(0,2)', heapOnly: true, hotUpdated: true),
        pointer(2, ctid: '(0,2)', heapOnly: true),
    ]);

    expect($page->hotChains())->toBe([]);
});

it('knows a page that is not laid out like a heap page', function (): void {
    // heap_page_items does not refuse a B-tree page: it decodes index tuples as
    // heap tuples and hands back a full, authoritative-looking answer made of
    // nothing. A heap page reserves no special area; an index page does.
    expect(pageWith([])->isHeapLayout())->toBeTrue();

    $indexPage = new HeapPage(
        block: 0,
        lsn: '0/0',
        lower: 32,
        upper: 8_176,
        special: 8_176,
        pageSize: 8_192,
        pruneXid: '0',
        pointers: [],
    );

    expect($indexPage->isHeapLayout())->toBeFalse();
});

it('does not start a second chain at a tuple the first one already ended on', function (): void {
    // Two updated, non-heap-only tuples in a row: the walk from the first steps
    // onto the second and stops there, because a tuple that is not heap-only has
    // left the chain. The second still looks like a root on its own terms, and
    // membership is what stops it being counted twice.
    $page = pageWith([
        pointer(1, ctid: '(0,2)', hotUpdated: true),
        pointer(2, ctid: '(0,3)', hotUpdated: true),
        pointer(3, ctid: '(0,3)', heapOnly: true),
    ]);

    $chains = $page->hotChains();

    expect($chains)->toHaveCount(1)
        ->and($chains[0]->lineNumbers)->toBe([1, 2]);
});
