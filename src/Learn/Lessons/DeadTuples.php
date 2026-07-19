<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn\Lessons;

use Heyosseus\Vacuum\Learn\Branch;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Learn\Observation;
use Heyosseus\Vacuum\Learn\Tier;
use Heyosseus\Vacuum\Learn\Tree;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Values\TableProfile;

/**
 * Shows a reader how many dead rows their own database is carrying right
 * now, and answers the question {@see TableProfile::vacuumsAt()} exists
 * for: not "is this table bloated" but "how many more dead rows before
 * autovacuum actually starts."
 */
final readonly class DeadTuples implements Lesson
{
    /** The worst offenders, not the whole catalog: the point is the shape, not a census. */
    private const int ROWS = 10;

    /** Below this dead share a table is not worth putting on the tree at all. */
    private const float POOR = 0.2;

    public function __construct(private TableProfiles $profiles) {}

    public function slug(): string
    {
        return 'dead-tuples';
    }

    public function title(): string
    {
        return 'Dead tuples and autovacuum';
    }

    public function tier(): Tier
    {
        return Tier::Maintenance;
    }

    public function hook(): string
    {
        return 'Find out how many dead rows your tables are carrying, and when autovacuum will act.';
    }

    public function after(): ?string
    {
        return null;
    }

    public function tree(): Tree
    {
        return $this->fork($this->profiles->all());
    }

    /**
     * The judgement, separated from the fetch so it can be exercised against
     * profiles that were built rather than measured.
     *
     * Public deliberately, for the same reason as {@see Fillfactor::fork()}:
     * a live database is never guaranteed to have both a table that has never been
     * vacuumed and one that has been vacuumed and is still bloated, and the fork
     * needs to be provably correct on both before it is trusted against real data.
     *
     * @param  list<TableProfile>  $profiles
     */
    public function fork(array $profiles): Tree
    {
        $poor = array_values(array_filter(
            $profiles,
            static fn (TableProfile $p): bool => $p->deadTupleRatio() > self::POOR,
        ));

        $neverVacuumed = array_values(array_filter($poor, static fn (TableProfile $p): bool => ! $p->lastVacuumedAt() instanceof \Carbon\CarbonImmutable));
        $stillStuck = array_values(array_filter($poor, static fn (TableProfile $p): bool => $p->lastVacuumedAt() instanceof \Carbon\CarbonImmutable));

        return new Tree('Has autovacuum even had a chance to run here?', [
            new Branch(
                condition: 'The table has never been vacuumed.',
                outcome: 'The threshold that starts autovacuum is a share of the table, not a fixed count, so '
                    .'a large table is allowed a large amount of garbage before anything moves. Lowering '
                    .'autovacuum_vacuum_scale_factor for this table makes it act sooner.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $neverVacuumed),
                fix: $neverVacuumed === []
                    ? null
                    : 'alter table '.$neverVacuumed[0]->qualifiedName().' set (autovacuum_vacuum_scale_factor = 0.05);',
            ),
            new Branch(
                condition: 'The table has been vacuumed and the dead share is still high.',
                outcome: 'Autovacuum ran and could not clean the rows up anyway. It cannot remove a row '
                    .'version newer than the oldest still-open transaction, so something -- a long-running '
                    .'query, an idle-in-transaction session, an unclosed replication slot -- is holding one open.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $stillStuck),
            ),
        ]);
    }

    public function observe(): Observation
    {
        $profiles = $this->profiles->all();

        if ($profiles === []) {
            return new Observation(
                headline: 'This database has no tables yet.',
                note: 'A dead tuple is a row a table has already lost; there is no table here to lose one.',
            );
        }

        $totalDead = array_sum(array_map(static fn (TableProfile $p): int => $p->deadTuples, $profiles));

        $ranked = $profiles;
        usort($ranked, static fn (TableProfile $a, TableProfile $b): int => $b->deadTuples <=> $a->deadTuples);

        $worst = $ranked[0];

        return new Observation(
            headline: 'This database is carrying '.number_format($totalDead).' dead rows across every table '
                ."it can see, and `{$worst->qualifiedName()}` holds the most: ".number_format($worst->deadTuples).'.',
            columns: ['table', 'live rows', 'dead rows', 'dead share', 'vacuums at', 'last vacuumed'],
            rows: array_map($this->toRow(...), array_slice($ranked, 0, self::ROWS)),
        );
    }

    public function tryIt(): string
    {
        return "select relname, n_live_tup, n_dead_tup, last_vacuum, last_autovacuum\n"
            ."from pg_stat_user_tables\n"
            .'order by n_dead_tup desc limit 10;';
    }

    /**
     * @return list<string>
     */
    private function toRow(TableProfile $profile): array
    {
        $lastVacuumed = $profile->lastVacuumedAt();

        return [
            $profile->qualifiedName(),
            number_format($profile->liveTuples),
            number_format($profile->deadTuples),
            number_format($profile->deadTupleRatio() * 100, 1).'%',
            number_format($profile->vacuumsAt()),
            $lastVacuumed?->format('j M Y') ?? 'never',
        ];
    }
}
