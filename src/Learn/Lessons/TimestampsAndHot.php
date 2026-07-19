<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn\Lessons;

use Heyosseus\Vacuum\Learn\Branch;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Learn\Observation;
use Heyosseus\Vacuum\Learn\Tier;
use Heyosseus\Vacuum\Learn\Tree;
use Heyosseus\Vacuum\Queries\Columns;
use Heyosseus\Vacuum\Queries\IndexStatistics;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Values\Column;
use Heyosseus\Vacuum\Values\IndexStatistic;
use Heyosseus\Vacuum\Values\TableProfile;

/**
 * Shows a reader which of their own tables carry an index on updated_at, and
 * what that index costs every save() rather than only the query it was built for.
 *
 * $table->timestamps() writes updated_at on every single save, whether or not
 * anything about the row actually changed. That is free on its own -- one more
 * column to write is nothing -- until an index gets put on updated_at to make
 * `orderBy('updated_at')` fast. From that moment, every update to the table
 * changes an indexed column, which disqualifies it from a HOT update before
 * PostgreSQL has even looked at whether the page had room. {@see Fillfactor}
 * covers the room side of that trade; this lesson covers the column side, which
 * fillfactor cannot fix at any setting.
 */
final readonly class TimestampsAndHot implements Lesson
{
    /**
     * The HOT-share floor below which a table with an index on updated_at is
     * worth putting on the tree. Set at the same level as {@see Fillfactor}'s
     * POOR constant for consistency across the two lessons a reader is likely
     * to read back to back, but it is still a judgement call rather than a
     * number PostgreSQL publishes: nothing about 80% is special except that a
     * healthy, mostly-append table rarely drops below it by accident.
     */
    private const float POOR = 0.8;

    public function __construct(
        private Columns $columns,
        private IndexStatistics $indexes,
        private TableProfiles $profiles,
    ) {}

    public function slug(): string
    {
        return 'timestamps-and-hot';
    }

    public function title(): string
    {
        return 'The index on updated_at that doubled your writes';
    }

    public function tier(): Tier
    {
        return Tier::Eloquent;
    }

    public function hook(): string
    {
        return 'One index on updated_at is enough to take every update on the table off the HOT path.';
    }

    public function after(): string
    {
        return 'fillfactor';
    }

    public function tree(): Tree
    {
        return $this->fork($this->profiles->all(), $this->tablesWithUpdatedAtIndex());
    }

    /**
     * The judgement, separated from the fetch so it can be exercised against
     * profiles and index matches that were built rather than measured.
     *
     * Public deliberately, for the same reason as {@see Fillfactor::fork()}: the
     * one thing this fork must get right -- that a poor HOT share caused by an
     * index on updated_at and one caused by something else are sent to
     * different outcomes -- is precisely what a live database cannot be relied
     * on to demonstrate side by side on demand.
     *
     * @param  list<TableProfile>  $profiles
     * @param  array<string, IndexStatistic>  $updatedAtIndexes  Table qualified
     *                                                           name to the index this lesson believes covers updated_at.
     */
    public function fork(array $profiles, array $updatedAtIndexes): Tree
    {
        $poor = array_values(array_filter(
            $profiles,
            static fn (TableProfile $p): bool => $p->updates > 0 && $p->hotUpdates / $p->updates < self::POOR,
        ));

        $indexed = array_values(array_filter(
            $poor,
            static fn (TableProfile $p): bool => isset($updatedAtIndexes[$p->qualifiedName()]),
        ));
        $notIndexed = array_values(array_filter(
            $poor,
            static fn (TableProfile $p): bool => ! isset($updatedAtIndexes[$p->qualifiedName()]),
        ));

        $fix = null;
        if ($indexed !== []) {
            $index = $updatedAtIndexes[$indexed[0]->qualifiedName()];

            // Only offered when the index has never been scanned: advising a
            // drop of an index something reads is not a fix, it is a new outage.
            if ($index->neverUsed()) {
                $fix = 'drop index concurrently '.$index->qualifiedName().';';
            }
        }

        return new Tree('Your HOT share is poor. Is the index on updated_at the reason?', [
            new Branch(
                condition: 'The table has an index on updated_at and a poor HOT share.',
                outcome: 'updated_at changes on every save, so every update touches that index, whatever else '
                    .'it changed -- no update on this table can ever be HOT, however much room the page has. '
                    .'Lowering fillfactor will not help here. Drop the index if nothing reads it (the fix below '
                    .'only appears when it has never been scanned), or accept the cost knowingly.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $indexed),
                fix: $fix,
            ),
            new Branch(
                condition: 'The table has a poor HOT share and no index on updated_at.',
                outcome: 'Something else is disqualifying these updates from HOT, or the page is simply full '
                    .'with no room for the new row version -- see the fillfactor lesson.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $notIndexed),
            ),
        ]);
    }

    public function observe(): Observation
    {
        $updatedAtIndexes = $this->tablesWithUpdatedAtIndex();

        $rows = array_values(array_filter(
            $this->profiles->all(),
            static fn (TableProfile $p): bool => isset($updatedAtIndexes[$p->qualifiedName()]),
        ));

        if ($rows === []) {
            return new Observation(
                headline: 'No table in this database has an index whose name mentions updated_at.',
                note: 'That is the good case: nothing here has traded away HOT updates for a faster sort on '
                    .'updated_at, and it is worth keeping it that way the next time a migration adds one.',
            );
        }

        usort(
            $rows,
            static fn (TableProfile $a, TableProfile $b): int => ($a->hotUpdateRatio() ?? 0.0) <=> ($b->hotUpdateRatio() ?? 0.0),
        );

        $worst = $rows[0];
        $worstIndex = $updatedAtIndexes[$worst->qualifiedName()];
        $ratio = $worst->hotUpdateRatio() ?? 0.0;
        $missed = number_format((1 - $ratio) * 100, 1);

        return new Observation(
            headline: "`{$worst->qualifiedName()}` carries `{$worstIndex->name}` on updated_at, and pays for "
                ."it: {$missed}% of its updates rewrote every index on the table, because updated_at changes "
                .'on every save.',
            columns: ['table', 'index on updated_at', 'updates', 'HOT share', 'fillfactor'],
            rows: array_map(
                fn (TableProfile $p): array => $this->toRow($p, $updatedAtIndexes[$p->qualifiedName()]),
                $rows,
            ),
        );
    }

    public function tryIt(): string
    {
        return "select stats.relname as table, indexes.indexname, stats.n_tup_upd,\n"
            ."    stats.n_tup_hot_upd::float8 / nullif(stats.n_tup_upd, 0) as hot_share\n"
            ."from pg_stat_user_tables stats\n"
            .'join pg_indexes indexes'
            ." on indexes.schemaname = stats.schemaname and indexes.tablename = stats.relname\n"
            ."where indexes.indexname ilike '%updated_at%'\n"
            .'order by hot_share nulls last;';
    }

    /**
     * Tables that have an updated_at column, mapped to the index this lesson
     * believes covers it.
     *
     * This is recognition by convention on two fronts at once. First, Vacuum
     * runs against a raw PostgreSQL connection and never autoloads the host
     * application's classes, so "this table has a timestamps() column" is read
     * from information_schema rather than from an Eloquent model. Second, and
     * more fragile: {@see IndexStatistic} carries an index's name but not its
     * column list, so the only signal available here is whether the index's own
     * name contains the substring "updated_at", case-insensitively. An index
     * that covers the column under an unrelated name -- a composite index
     * called orders_search_idx that happens to include updated_at, or a
     * hand-written index with no naming convention at all -- will not be found,
     * and this map will under-report. A table that has renamed its own
     * timestamp column away from the Eloquent default will also not be found by
     * the column check. Both are real limitations of matching by name, not an
     * oversight, and this docblock is the honest version of that.
     *
     * @return array<string, IndexStatistic>
     */
    private function tablesWithUpdatedAtIndex(): array
    {
        $tablesWithColumn = [];

        foreach ($this->columns->all() as $column) {
            if ($column->name === 'updated_at') {
                $tablesWithColumn[$column->qualifiedName()] = true;
            }
        }

        $matches = [];

        foreach ($this->indexes->all() as $index) {
            $table = $index->schema.'.'.$index->table;
            if (! isset($tablesWithColumn[$table])) {
                continue;
            }
            if (isset($matches[$table])) {
                continue;
            }

            if (str_contains(strtolower($index->name), 'updated_at')) {
                $matches[$table] = $index;
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function toRow(TableProfile $profile, IndexStatistic $index): array
    {
        $ratio = $profile->hotUpdateRatio();

        return [
            $profile->qualifiedName(),
            $index->name,
            number_format($profile->updates),
            $ratio === null ? '—' : number_format($ratio * 100, 1).'%',
            $profile->fillfactor === null ? '100 (default)' : (string) $profile->fillfactor,
        ];
    }
}
