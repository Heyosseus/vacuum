<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Internals\Explorers;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Internals\Availability;
use Heyosseus\Vacuum\Internals\Decoders\InfoMask;
use Heyosseus\Vacuum\Internals\Decoders\LinePointerFlags;
use Heyosseus\Vacuum\Internals\Explorer;
use Heyosseus\Vacuum\Internals\Support\RelationCatalog;
use Heyosseus\Vacuum\Internals\Values\HeapPage;
use Heyosseus\Vacuum\Internals\Values\LinePointer;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Opens one real 8 kB heap page and reads its header and line pointers with
 * pageinspect, so a reader can see a dead tuple, a HOT chain, or a row's
 * xmin/xmax the way PostgreSQL itself stores them rather than as a summary.
 *
 * pageinspect's functions are superuser-restricted by default even once the
 * extension is installed, which is the common case on managed platforms
 * rather than the exception -- so availability() actually calls one before
 * answering, rather than trusting that the extension being present means
 * the connected role may use it.
 */
final readonly class HeapPages implements Explorer
{
    public function __construct(
        private ReadOnlyExecutor $executor,
        private RelationCatalog $relations,
        private Capabilities $capabilities,
        private Repository $config,
    ) {}

    public function availability(): Availability
    {
        if (! (bool) $this->config->get('vacuum.internals.enabled', false)) {
            return Availability::disabled();
        }

        if (! $this->capabilities->has('pageinspect')) {
            return Availability::missingExtension('pageinspect');
        }

        try {
            $this->probeRawPage();
        } catch (QueryException) {
            return Availability::insufficientPrivilege('superuser (pageinspect is superuser-restricted)');
        }

        return Availability::available();
    }

    /**
     * Calls a raw page function against a relation that is always present,
     * purely to see whether the connected role is allowed to. pg_class
     * always exists and always has at least one page, so a failure here can
     * only be the superuser restriction pageinspect's own functions carry,
     * never a missing relation.
     */
    private function probeRawPage(): void
    {
        $this->executor->select('SELECT get_raw_page(?, ?)', ['pg_class', 0]);
    }

    /**
     * How many 8 kB pages the relation currently occupies, the range a
     * block number must fall inside before it may reach get_raw_page.
     */
    public function blockCount(string $schema, string $table): int
    {
        return $this->blocksFor($this->relations->resolve($schema, $table));
    }

    public function explore(string $schema, string $table, int $block): HeapPage
    {
        $relation = $this->relations->resolve($schema, $table);
        $blocks = $this->blocksFor($relation);

        if ($block < 0 || $block >= $blocks) {
            throw new InvalidArgumentException(
                "Block {$block} is out of range for {$schema}.{$table}, which has {$blocks} block(s).",
            );
        }

        $header = $this->executor->select(
            'SELECT lsn::text AS lsn, lower, upper, special, pagesize, prune_xid::text AS prune_xid '
            .'FROM page_header(get_raw_page(?, ?))',
            [$relation, $block],
        )[0] ?? [];

        $items = $this->executor->select(
            'SELECT lp, lp_off, lp_flags, lp_len, t_xmin::text AS t_xmin, t_xmax::text AS t_xmax, '
            .'t_ctid::text AS t_ctid, t_infomask, t_infomask2 FROM heap_page_items(get_raw_page(?, ?))',
            [$relation, $block],
        );

        return new HeapPage(
            block: $block,
            lsn: Cast::text($header['lsn'] ?? null),
            lower: Cast::integer($header['lower'] ?? null),
            upper: Cast::integer($header['upper'] ?? null),
            special: Cast::integer($header['special'] ?? null),
            pageSize: Cast::integer($header['pagesize'] ?? null),
            pruneXid: Cast::text($header['prune_xid'] ?? null),
            pointers: array_map(fn (array $item): LinePointer => $this->toPointer($item, $block), $items),
        );
    }

    /**
     * The first block in the sample bound for which the reader's question
     * has an answer, or null when none of the pages read did. The sample is
     * bounded by both the relation's real size and the configured limit,
     * whichever is smaller, so a huge table is read at most a few hundred
     * pages deep rather than end to end.
     */
    public function findInteresting(string $schema, string $table, string $what): ?int
    {
        $relation = $this->relations->resolve($schema, $table);
        $blocks = $this->blocksFor($relation);
        $sampleLimit = Cast::integer($this->config->get('vacuum.internals.page_sample_limit', 100));
        $limit = min($blocks, $sampleLimit);

        for ($block = 0; $block < $limit; $block++) {
            $page = $this->explore($schema, $table, $block);

            if ($this->matches($page, $what)) {
                return $block;
            }
        }

        return null;
    }

    private function matches(HeapPage $page, string $what): bool
    {
        if ($what === 'dead') {
            return $this->hasDeadPointer($page);
        }

        if ($what === 'hot') {
            return $page->hotChains() !== [];
        }

        return false;
    }

    private function hasDeadPointer(HeapPage $page): bool
    {
        foreach ($page->pointers as $pointer) {
            if ($pointer->isDead) {
                return true;
            }
        }

        return false;
    }

    private function blocksFor(string $relation): int
    {
        $row = $this->executor->select(
            "SELECT (pg_relation_size(?::regclass) / current_setting('block_size')::int)::int AS blocks",
            [$relation],
        )[0] ?? [];

        return Cast::integer($row['blocks'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toPointer(array $item, int $block): LinePointer
    {
        $flags = Cast::integer($item['lp_flags'] ?? null);
        $state = LinePointerFlags::describe($flags);
        $infomask = Cast::integer($item['t_infomask'] ?? null);
        $infomask2 = Cast::integer($item['t_infomask2'] ?? null);
        $lineNumber = Cast::integer($item['lp'] ?? null);
        $offset = Cast::integer($item['lp_off'] ?? null);

        // A redirect line pointer stores no tuple of its own -- t_ctid is
        // null -- but its lp_off is the line number it points at, which is
        // exactly what a ctid names on the same page.
        $ctid = $this->nullableText($item['t_ctid'] ?? null)
            ?? ($state === 'redirect' ? "({$block},{$offset})" : null);

        return new LinePointer(
            lineNumber: $lineNumber,
            offset: $offset,
            state: $state,
            length: Cast::integer($item['lp_len'] ?? null),
            xmin: $this->nullableText($item['t_xmin'] ?? null),
            xmax: $this->nullableText($item['t_xmax'] ?? null),
            ctid: $ctid,
            flags: InfoMask::describe($infomask, $infomask2),
            isDead: $state === 'dead',
            isRedirect: $state === 'redirect',
            heapOnly: InfoMask::heapOnlyTuple($infomask2),
            hotUpdated: InfoMask::hotUpdated($infomask2),
        );
    }

    private function nullableText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
