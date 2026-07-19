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
     * Every HOT chain on the page, found by following each redirect line
     * pointer to the heap-only tuples it leads to.
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

        foreach ($this->pointers as $pointer) {
            if ($pointer->isRedirect) {
                $chains[] = $this->follow($pointer, $byLineNumber);
            }
        }

        return $chains;
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
