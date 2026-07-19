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
 * Shows a reader which of their own tables pays the most for the room it
 * left itself to update in place.
 *
 * {@see TableProfile::hotUpdateRatio()} is the number the lesson is built
 * around: the share of updates PostgreSQL managed to chain onto the same
 * page without touching a single index. Ordering by it, worst first, puts
 * the table that would benefit most from a lower fillfactor at the top.
 */
final readonly class Fillfactor implements Lesson
{
    /** Enough tables to see the pattern without turning the page into a census. */
    private const int ROWS = 10;

    /**
     * The share above which a table is no longer worth arguing about. Nothing
     * reaches exactly 1.0 for long on a live table, so the all-clear cannot be
     * spelled as equality.
     */
    private const float CHAINED_ENOUGH = 0.95;

    /** Below this share a table is worth putting on the tree at all. */
    private const float POOR = 0.8;

    public function __construct(private TableProfiles $profiles) {}

    public function slug(): string
    {
        return 'fillfactor';
    }

    public function title(): string
    {
        return 'Fillfactor and HOT updates';
    }

    public function tier(): Tier
    {
        return Tier::Storage;
    }

    public function hook(): string
    {
        return 'See which of your tables rewrite every index on every update, and why.';
    }

    public function after(): string
    {
        return 'row-versions';
    }

    public function tree(): Tree
    {
        return $this->fork($this->profiles->all());
    }

    /**
     * The judgement, separated from the fetch so it can be exercised against
     * profiles that were built rather than measured.
     *
     * Public deliberately. A live database has whatever statistics it happens to
     * have, and the one thing this fork must get right -- that two tables with an
     * identical HOT share and different causes are sent to different fixes -- is
     * precisely what a live database cannot be relied on to demonstrate.
     *
     * @param  list<TableProfile>  $profiles
     */
    public function fork(array $profiles): Tree
    {
        $poor = array_values(array_filter(
            $profiles,
            static fn (TableProfile $p): bool => $p->updates > 0 && $p->hotUpdates / $p->updates < self::POOR,
        ));

        $roomToGain = array_values(array_filter($poor, static fn (TableProfile $p): bool => $p->fillfactor === null));
        $alreadyLowered = array_values(array_filter($poor, static fn (TableProfile $p): bool => $p->fillfactor !== null));

        return new Tree('Is a low HOT share worth fixing here?', [
            new Branch(
                condition: 'The table is still at the default fillfactor of 100.',
                outcome: 'There is no room on the page for the new row version, so every update leaves the '
                    .'page and pays every index. Leaving some room is the cheap fix.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $roomToGain),
                fix: $roomToGain === []
                    ? null
                    : 'alter table '.$roomToGain[0]->qualifiedName().' set (fillfactor = 85);',
            ),
            new Branch(
                condition: 'The table already lowered its fillfactor and the share is still poor.',
                outcome: 'Room is not what it is short of. An update that changes any indexed column is '
                    .'disqualified from HOT before the page is even consulted, so look for an index on a '
                    .'column that changes constantly — updated_at is the usual one — and drop it if nothing '
                    .'reads it.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $alreadyLowered),
            ),
        ]);
    }

    public function observe(): Observation
    {
        $profiles = $this->profiles->all();
        $updated = array_values(array_filter($profiles, static fn (TableProfile $p): bool => $p->updates > 0));

        if ($updated === []) {
            return new Observation(
                headline: 'None of the '.count($profiles).' table(s) in this database have logged a single '
                    .'update since PostgreSQL started counting.',
                note: 'The HOT-update share needs writes to exist before it can say anything. '.
                    'Update a row and reload this page.',
            );
        }

        usort(
            $updated,
            static fn (TableProfile $a, TableProfile $b): int => ($a->hotUpdateRatio() ?? 0.0) <=> ($b->hotUpdateRatio() ?? 0.0),
        );

        $worst = $updated[0];
        $chained = $worst->hotUpdateRatio() ?? 0.0;
        $missed = number_format((1 - $chained) * 100, 1);

        return new Observation(
            // The worst table in a healthy database is still a good table, and saying
            // "the lowest share is 100%" reads as a contradiction rather than as the
            // all-clear it actually is. So the sentence changes shape, not just its
            // numbers, once nothing here is paying for its updates.
            headline: $chained >= self::CHAINED_ENOUGH
                ? 'Every table in this database chains almost all of its updates into the same page. '
                    ."The worst of them, `{$worst->qualifiedName()}`, still keeps "
                    .number_format($chained * 100, 1).'% of its updates off the indexes entirely.'
                : "`{$worst->qualifiedName()}` gets the least out of HOT updates in this database: "
                    ."{$missed}% of its updates rewrote every index on the table, because the new row "
                    .'version did not fit in the page the old one was on.',
            columns: ['table', 'updates', 'HOT updates', 'HOT share', 'fillfactor'],
            rows: array_map($this->toRow(...), array_slice($updated, 0, self::ROWS)),
        );
    }

    public function tryIt(): string
    {
        return "select relname, n_tup_upd, n_tup_hot_upd\n"
            ."from pg_stat_user_tables\n"
            ."where n_tup_upd > 0\n"
            .'order by n_tup_hot_upd::float8 / n_tup_upd limit 10;';
    }

    /**
     * @return list<string>
     */
    private function toRow(TableProfile $profile): array
    {
        $ratio = $profile->hotUpdateRatio();

        return [
            $profile->qualifiedName(),
            number_format($profile->updates),
            number_format($profile->hotUpdates),
            $ratio === null ? '—' : number_format($ratio * 100, 1).'%',
            $profile->fillfactor === null ? '100 (default)' : (string) $profile->fillfactor,
        ];
    }
}
