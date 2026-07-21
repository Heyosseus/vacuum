<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Internals\Values;

use Heyosseus\Vacuum\Internals\Decoders\Ctid;

/**
 * One 8 kB heap page, read whole: its header and every line pointer on it.
 *
 * hotChains() is pure logic over the page's own pointers -- no database
 * access, no I/O -- which is what makes it unit-testable without a real
 * connection even though the page it describes never comes from anywhere
 * but a real one.
 */
final readonly class HeapPage
{
    /**
     * @param  list<LinePointer>  $pointers
     */
    public function __construct(
        public int $block,
        public string $lsn,
        public int $lower,
        public int $upper,
        public int $special,
        public int $pageSize,
        public string $pruneXid,
        public array $pointers,
    ) {}

    /**
     * The room left for a new tuple before the page fills: the gap between
     * where the line pointer array is growing from and where the tuple data
     * is growing down from.
     */
    public function freeBytes(): int
    {
        return $this->upper - $this->lower;
    }

    /**
     * Whether this page is laid out the way heap_page_items just read it.
     *
     * A heap page reserves no special area, so its special offset sits at the
     * very end of the page. An index page does reserve one. The distinction
     * matters because heap_page_items does not refuse a B-tree page -- it decodes
     * index tuples as though they were heap tuples and returns a full,
     * authoritative-looking panel of nothing, which is the worst way for a
     * teaching tool to be wrong. RelationCatalog turns away the relkinds this can
     * happen for and is the real fix; this is the second lock on the same door,
     * and it is here rather than in the reader because a page can say for itself
     * whether it is the shape it was read as.
     */
    public function isHeapLayout(): bool
    {
        return $this->special === $this->pageSize;
    }

    /**
     * Every HOT chain on the page, found by following each chain root to the
     * heap-only tuples it leads to.
     *
     * A chain has two possible roots and only one of them is a redirect. A
     * redirect appears when the page is *pruned* -- the root tuple's storage is
     * reclaimed and its line pointer is left behind pointing at the survivor.
     * Before that happens, and it has not happened on a live table between
     * autovacuums, the root is an ordinary LP_NORMAL tuple with HEAP_HOT_UPDATED
     * set and its t_ctid pointing at the next version.
     *
     * Looking only for redirects therefore finds chains exactly when a vacuum has
     * already been through, and reports "no HOT chains on this page" for the most
     * common state a busy table is ever in -- which is precisely the state
     * somebody tuning fillfactor opened this page to look at.
     *
     * The second root condition is hotUpdated && ! heapOnly: something updated
     * this tuple within the page, and it is not itself a link in an earlier
     * chain. Membership is tracked across the whole page so a tuple already
     * walked into is never made the root of a second chain.
     *
     * @return list<HotChain>
     */
    public function hotChains(): array
    {
        $byLineNumber = [];

        foreach ($this->pointers as $pointer) {
            $byLineNumber[$pointer->lineNumber] = $pointer;
        }

        $chains = [];
        $claimed = [];

        foreach ($this->pointers as $pointer) {
            if (! $this->rootsChain($pointer)) {
                continue;
            }
            if (isset($claimed[$pointer->lineNumber])) {
                continue;
            }
            $chain = $this->follow($pointer, $byLineNumber);

            foreach ($chain->lineNumbers as $lineNumber) {
                $claimed[$lineNumber] = true;
            }

            $chains[] = $chain;
        }

        return $chains;
    }

    /**
     * Whether a chain starts here: a redirect left by pruning, or an updated
     * tuple that is not itself heap-only and so is the original row rather than
     * a later version of one.
     */
    private function rootsChain(LinePointer $pointer): bool
    {
        return $pointer->isRedirect || ($pointer->hotUpdated && ! $pointer->heapOnly);
    }

    /**
     * @param  array<int, LinePointer>  $byLineNumber
     */
    private function follow(LinePointer $root, array $byLineNumber): HotChain
    {
        $lineNumbers = [$root->lineNumber];

        // Every line number the walk has already stepped onto, so a chain a
        // corrupt page has bent back on itself is a wrong answer -- stopping
        // one step early -- rather than a request that never returns.
        $visited = [$root->lineNumber => true];

        $current = $root;

        while (true) {
            $next = $this->next($current, $byLineNumber);

            if (! $next instanceof LinePointer || isset($visited[$next->lineNumber])) {
                break;
            }

            $lineNumbers[] = $next->lineNumber;
            $visited[$next->lineNumber] = true;

            // The chain ends at the tuple whose ctid points at itself -- the
            // row's current version -- or the moment the next link is not
            // itself a heap-only tuple, which means it left the HOT chain
            // and became an ordinary update.
            if (Ctid::pointsToSelf($next->ctid ?? '', $this->block, $next->lineNumber) || ! $next->heapOnly) {
                break;
            }

            $current = $next;
        }

        return new HotChain(
            rootLineNumber: $root->lineNumber,
            lineNumbers: $lineNumbers,
            length: count($lineNumbers),
        );
    }

    /**
     * @param  array<int, LinePointer>  $byLineNumber
     */
    private function next(LinePointer $pointer, array $byLineNumber): ?LinePointer
    {
        $parsed = Ctid::parse($pointer->ctid ?? '');

        if ($parsed['block'] !== $this->block) {
            // The chain's next link lives on another page, which a single
            // page's picture cannot show.
            return null;
        }

        return $byLineNumber[$parsed['offset']] ?? null;
    }
}
