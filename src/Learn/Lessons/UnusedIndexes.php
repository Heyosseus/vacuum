<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn\Lessons;

use Heyosseus\Vacuum\Learn\Branch;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Learn\Observation;
use Heyosseus\Vacuum\Learn\Tier;
use Heyosseus\Vacuum\Learn\Tree;
use Heyosseus\Vacuum\Queries\IndexStatistics;
use Heyosseus\Vacuum\Support\Bytes;
use Heyosseus\Vacuum\Values\IndexStatistic;

/**
 * Shows a reader which of their own indexes have never been read, and what
 * they cost to keep.
 *
 * An index nothing reads is not automatically an index worth dropping: a
 * primary key or a unique index does its job on every insert and update
 * whether or not a query has ever used it to look something up, which is
 * exactly what {@see IndexStatistic::constrains()} exists to tell apart
 * from {@see IndexStatistic::neverUsed()}.
 */
final readonly class UnusedIndexes implements Lesson
{
    public function __construct(private IndexStatistics $indexes) {}

    public function slug(): string
    {
        return 'unused-indexes';
    }

    public function title(): string
    {
        return 'What an index costs';
    }

    public function tier(): Tier
    {
        return Tier::Indexes;
    }

    public function hook(): string
    {
        return 'Find the indexes your database maintains on every write and reads on none.';
    }

    public function after(): ?string
    {
        return null;
    }

    public function tree(): Tree
    {
        return $this->fork($this->indexes->all());
    }

    /**
     * The judgement, separated from the fetch so it can be exercised against
     * indexes that were built rather than measured.
     *
     * Public deliberately, for the same reason as {@see Fillfactor::fork()}:
     * the fork this lesson resolves -- constrains the table versus costs it nothing
     * but writes -- has to be proven against a constraining and a non-constraining
     * unused index side by side, and a live database offers no guarantee it has both.
     *
     * @param  list<IndexStatistic>  $indexes
     */
    public function fork(array $indexes): Tree
    {
        $unused = array_values(array_filter($indexes, static fn (IndexStatistic $index): bool => $index->neverUsed()));

        $constraining = array_values(array_filter($unused, static fn (IndexStatistic $index): bool => $index->constrains()));
        $pureCost = array_values(array_filter($unused, static fn (IndexStatistic $index): bool => ! $index->constrains()));

        return new Tree('What is an unread index for?', [
            new Branch(
                condition: 'The index enforces a uniqueness or primary key constraint.',
                outcome: 'It is doing its job on every insert and update whether or not a query has ever '
                    .'used it to look something up. Leave it.',
                landed: array_map(static fn (IndexStatistic $index): string => $index->qualifiedName(), $constraining),
            ),
            new Branch(
                condition: 'The index enforces no constraint and nothing has ever read it.',
                outcome: 'It costs a write on every insert, update, and delete to the table, and returns '
                    .'nothing for it. Dropping it removes pure cost.',
                landed: array_map(static fn (IndexStatistic $index): string => $index->qualifiedName(), $pureCost),
                fix: $pureCost === []
                    ? null
                    : 'drop index concurrently '.$pureCost[0]->qualifiedName().';',
            ),
        ]);
    }

    public function observe(): Observation
    {
        $indexes = $this->indexes->all();

        $unused = array_values(array_filter(
            $indexes,
            static fn (IndexStatistic $index): bool => $index->neverUsed() && ! $index->constrains(),
        ));

        if ($unused === []) {
            return new Observation(
                headline: 'This database has '.count($indexes).' index(es), and every one of them either has '
                    .'been read at least once or exists to enforce a constraint.',
                note: 'There is nothing to flag here: an unused, non-constraining index is exactly what this '.
                    'lesson looks for, and this database does not have one right now.',
            );
        }

        usort($unused, static fn (IndexStatistic $a, IndexStatistic $b): int => $b->bytes <=> $a->bytes);

        $totalBytes = array_sum(array_map(static fn (IndexStatistic $index): int => $index->bytes, $unused));

        return new Observation(
            headline: 'This database has '.count($unused).' index(es) that have never been read, occupying '
                .Bytes::human($totalBytes).'.',
            columns: ['index', 'table', 'size', 'scans'],
            rows: array_map($this->toRow(...), $unused),
        );
    }

    public function tryIt(): string
    {
        return "select indexrelname, relname, idx_scan, pg_size_pretty(pg_relation_size(indexrelid)) as size\n"
            ."from pg_stat_user_indexes\n"
            ."where idx_scan = 0\n"
            .'order by pg_relation_size(indexrelid) desc limit 10;';
    }

    /**
     * @return list<string>
     */
    private function toRow(IndexStatistic $index): array
    {
        return [
            $index->qualifiedName(),
            $index->schema.'.'.$index->table,
            Bytes::human($index->bytes),
            number_format($index->scans),
        ];
    }
}
