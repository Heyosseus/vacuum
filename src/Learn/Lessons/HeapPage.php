<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn\Lessons;

use Heyosseus\Vacuum\Internals\Explorers\HeapPages;
use Heyosseus\Vacuum\Learn\Branch;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Learn\Observation;
use Heyosseus\Vacuum\Learn\Tier;
use Heyosseus\Vacuum\Learn\Tree;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Support\Bytes;
use Heyosseus\Vacuum\Values\Capabilities;
use Heyosseus\Vacuum\Values\TableProfile;
use Illuminate\Contracts\Config\Repository;

/**
 * The front door to the internals explorer: this is where a reader who has
 * only ever seen a table as rows in a result set learns it is really a file
 * of fixed-size pages, and where they are pointed at the one place in this
 * package that opens a real one.
 *
 * The internals explorers ({@see HeapPages}) are orphaned by design until a
 * reader opts in -- pageinspect reads raw pages and is usually
 * superuser-restricted, so Vacuum never assumes it is welcome. This lesson
 * is the thing that tells a reader the explorer exists at all, and the fork
 * below is not about any table: it is about whether *this* connection, on
 * *this* server, is even allowed to look.
 */
final readonly class HeapPage implements Lesson
{
    /** Enough tables to see the pattern without turning the page into a census. */
    private const int ROWS = 10;

    /**
     * PostgreSQL's compile-time page size, BLCKSZ. Every packaged build --
     * apt, yum, the official Docker images, every managed provider -- ships
     * the default of 8 kB, so this is the number worth stating plainly
     * rather than pretending it varies in practice. It is not a constant
     * PostgreSQL exposes as a setting: a server built from source with a
     * different --with-blocksize would make every division below wrong,
     * and there is no catalog view this package can read to be sure which
     * one it is talking to.
     */
    private const int PAGE_BYTES = 8192;

    public function __construct(
        private TableProfiles $profiles,
        private Capabilities $capabilities,
        private HeapPages $heapPages,
        private Repository $config,
    ) {}

    public function slug(): string
    {
        return 'heap-page';
    }

    public function title(): string
    {
        return 'Inside a heap page';
    }

    public function tier(): Tier
    {
        return Tier::Advanced;
    }

    public function hook(): string
    {
        return 'See how many 8 kB pages your own largest table actually occupies, and open a real one.';
    }

    public function after(): string
    {
        return 'row-versions';
    }

    public function tree(): Tree
    {
        return $this->fork(
            $this->capabilities->has('pageinspect'),
            (bool) $this->config->get('vacuum.internals.enabled', false),
            $this->heapPages->availability()->available,
        );
    }

    /**
     * The judgement, separated from the fetch so it can be exercised against
     * a server that was described rather than measured.
     *
     * Public deliberately, for the same reason as {@see Fillfactor::fork()}:
     * Capabilities, the internals config flag and {@see HeapPages::availability()}
     * all read a live connection this package cannot mock, and the one thing
     * this fork must get right -- landing on exactly one of three mutually
     * exclusive server states -- is precisely what a live test database
     * cannot be relied on to demonstrate on demand (it will almost never be
     * running as a role that lacks superuser, for one).
     *
     * $canOpenPage is {@see HeapPages::availability()}'s own verdict, which
     * already accounts for the config flag and a live probe of the
     * superuser restriction pageinspect's functions carry -- it is passed
     * in here, rather than recomputed, so this method stays a pure
     * judgement over booleans and never issues a query of its own.
     */
    public function fork(bool $pageinspectInstalled, bool $internalsEnabled, bool $canOpenPage): Tree
    {
        $canOpen = $pageinspectInstalled && $internalsEnabled && $canOpenPage;
        $switchedOff = $pageinspectInstalled && ! $internalsEnabled;
        $cannotOpen = ! $canOpen && ! $switchedOff;

        return new Tree('Can you look inside a page on this server?', [
            new Branch(
                condition: 'pageinspect is installed and the internals section is switched on.',
                outcome: 'You can open a real page from a real table on this connection and see its line '
                    .'pointers, its HOT chains and its dead tuples exactly as PostgreSQL stores them.',
                landed: $canOpen ? ['this connection'] : [],
            ),
            new Branch(
                condition: 'pageinspect is installed, but the internals section is switched off.',
                outcome: 'It is off by default because it reads raw pages. Turning it on is one setting.',
                landed: $switchedOff ? ['this connection'] : [],
                fix: $switchedOff ? 'VACUUM_INTERNALS_ENABLED=true' : null,
            ),
            new Branch(
                condition: 'pageinspect is not installed, or the connected role is not superuser.',
                outcome: 'This is the normal case on managed PostgreSQL -- RDS, Cloud SQL, Azure, Supabase, '
                    .'Neon -- none of which grant superuser, and pageinspect is superuser-restricted even '
                    .'once it is installed. The rest of the Learn section deliberately needs neither: '
                    .'everything above still holds, you simply cannot open a page here to check it yourself.',
                landed: $cannotOpen ? ['this connection'] : [],
                fix: 'create extension pageinspect; -- needs superuser',
            ),
        ]);
    }

    public function observe(): Observation
    {
        $profiles = $this->profiles->all();

        if ($profiles === []) {
            return new Observation(
                headline: 'This database has no tables yet.',
                note: 'A page count needs a table to divide into pages, and there is no table here to divide.',
            );
        }

        $ranked = $profiles;
        usort($ranked, static fn (TableProfile $a, TableProfile $b): int => $b->heapBytes <=> $a->heapBytes);

        $largest = $ranked[0];
        $pages = $this->pages($largest->heapBytes);
        $rowsPerPage = $this->rowsPerPage($largest->liveTuples, $pages);

        return new Observation(
            headline: "`{$largest->qualifiedName()}` is this database's largest table: ".
                number_format($pages).' page(s) of 8 kB each, holding an average of '.
                number_format($rowsPerPage, 1).' row(s) per page.',
            columns: ['table', 'heap size', 'pages', 'live rows', 'rows per page'],
            rows: array_map($this->toRow(...), array_slice($ranked, 0, self::ROWS)),
        );
    }

    public function tryIt(): string
    {
        return "select relname,\n"
            ."       pg_relation_size(oid) as heap_bytes,\n"
            ."       pg_relation_size(oid) / current_setting('block_size')::int as pages\n"
            .'from pg_class where relkind = '."'r'\n"
            .'order by pg_relation_size(oid) desc limit 10;';
    }

    /**
     * How many 8 kB pages a heap of this size occupies, rounded to the
     * nearest whole page. A table with no rows has never had a page
     * allocated for it at all, and 0 bytes divided by anything is still 0
     * -- there is no zero-division risk here, only the honest answer that
     * an empty table occupies no pages yet.
     */
    private function pages(int $heapBytes): int
    {
        return $heapBytes <= 0 ? 0 : (int) round($heapBytes / self::PAGE_BYTES);
    }

    /**
     * The average number of live rows a page of this table is carrying.
     * Zero pages would make this a division by zero rather than a
     * meaningful average, so a table with nothing allocated yet is reported
     * as zero rather than as NAN.
     */
    private function rowsPerPage(int $liveTuples, int $pages): float
    {
        return $pages === 0 ? 0.0 : $liveTuples / $pages;
    }

    /**
     * @return list<string>
     */
    private function toRow(TableProfile $profile): array
    {
        $pages = $this->pages($profile->heapBytes);
        $rowsPerPage = $this->rowsPerPage($profile->liveTuples, $pages);

        return [
            $profile->qualifiedName(),
            Bytes::human($profile->heapBytes),
            number_format($pages),
            number_format($profile->liveTuples),
            number_format($rowsPerPage, 1),
        ];
    }
}
