<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn\Lessons;

use Heyosseus\Vacuum\Learn\Branch;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Learn\Observation;
use Heyosseus\Vacuum\Learn\Tier;
use Heyosseus\Vacuum\Learn\Tree;
use Heyosseus\Vacuum\Queries\Constraints;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Values\Constraint;
use Heyosseus\Vacuum\Values\TableProfile;

/**
 * Shows a reader every foreign key in their own database that PostgreSQL
 * did not index for them, next to the size of the table that pays for it.
 *
 * $table->foreignId('customer_id')->constrained() writes a constraint and
 * nothing else. PostgreSQL indexes a primary key and a unique constraint
 * automatically because both need an index to enforce uniqueness; a foreign
 * key needs no index to enforce itself, only a lookup on the parent side
 * at insert time, which the parent's own primary key already serves. So it
 * gets none. MySQL, which a lot of Laravel developers learned schema design
 * on, indexes every foreign key whether it needs one or not -- which is why
 * a schema that was fast there arrives here with a trap nobody meant to set.
 */
final readonly class UnindexedForeignKeys implements Lesson
{
    /**
     * The row count above which a missing index on a foreign key stops being
     * a rounding error and starts being a lock held over a sequential scan.
     * There is no version of this number PostgreSQL will hand back: ten
     * thousand rows is cheap to scan on most hardware and expensive on none
     * of it, so this is a judgement call about where "cheap" plausibly ends,
     * not a measured threshold.
     */
    private const int LARGE = 10_000;

    public function __construct(
        private Constraints $constraints,
        private TableProfiles $profiles,
    ) {}

    public function slug(): string
    {
        return 'unindexed-foreign-keys';
    }

    public function title(): string
    {
        return 'The index Eloquent does not create';
    }

    public function tier(): Tier
    {
        return Tier::Eloquent;
    }

    public function hook(): string
    {
        return 'Find the foreign keys on your own tables that turn a parent delete into a full scan.';
    }

    public function after(): ?string
    {
        return null;
    }

    public function tree(): Tree
    {
        return $this->fork($this->constraints->all(), $this->profiles->all());
    }

    /**
     * The judgement, separated from the fetch so it can be exercised against
     * constraints and profiles that were built rather than queried.
     *
     * Public deliberately, for the same reason as {@see Fillfactor::fork()}:
     * Constraints and TableProfiles are both final readonly classes wrapping
     * a read-only executor, so nothing about a live query can be mocked, and
     * the one thing this fork must get right -- that an unindexed foreign key
     * on a small table and one on a large table are sent to different
     * outcomes -- is precisely what a live database cannot be relied on to
     * demonstrate on demand.
     *
     * @param  list<Constraint>  $constraints
     * @param  list<TableProfile>  $profiles
     */
    public function fork(array $constraints, array $profiles): Tree
    {
        $rows = $this->rowCounts($profiles);

        $unindexed = array_values(array_filter(
            $constraints,
            static fn (Constraint $c): bool => $c->isForeignKey() && ! $c->indexed,
        ));

        $large = array_values(array_filter(
            $unindexed,
            fn (Constraint $c): bool => ($rows[$c->qualifiedName()] ?? 0) >= self::LARGE,
        ));

        $small = array_values(array_filter(
            $unindexed,
            fn (Constraint $c): bool => ($rows[$c->qualifiedName()] ?? 0) < self::LARGE,
        ));

        usort(
            $large,
            static fn (Constraint $a, Constraint $b): int => ($rows[$b->qualifiedName()] ?? 0) <=> ($rows[$a->qualifiedName()] ?? 0),
        );

        return new Tree('Which unindexed foreign keys actually cost you anything?', [
            new Branch(
                condition: 'The child table already holds '.number_format(self::LARGE).' rows or more.',
                outcome: 'Every delete or key update on the parent has to prove no row here still points at '
                    .'it, and with no index that proof is a sequential scan of the whole table, held for the '
                    .'duration of the lock. Add the index.',
                landed: array_map(static fn (Constraint $c): string => $c->qualifiedName().'.'.implode(',', $c->columns), $large),
                fix: $large === [] ? null : $this->createIndexStatement($large[0]),
            ),
            new Branch(
                condition: 'The child table is still under '.number_format(self::LARGE).' rows.',
                outcome: 'The scan is cheap today, so the missing index is not costing much yet. It will not '
                    .'stay small forever, and building the index now -- before the table is big enough to make '
                    .'CONCURRENTLY worth reaching for -- is cheaper than building it under load later.',
                landed: array_map(static fn (Constraint $c): string => $c->qualifiedName().'.'.implode(',', $c->columns), $small),
            ),
        ]);
    }

    public function observe(): Observation
    {
        $constraints = $this->constraints->all();
        $rows = $this->rowCounts($this->profiles->all());

        $unindexed = array_values(array_filter(
            $constraints,
            static fn (Constraint $c): bool => $c->isForeignKey() && ! $c->indexed,
        ));

        if ($unindexed === []) {
            return new Observation(
                headline: 'None of the foreign keys in this database are missing an index.',
                note: 'PostgreSQL indexes a primary key and a unique constraint automatically, because both '
                    .'need one to enforce themselves. It never indexes a foreign key -- there is nothing to '
                    .'enforce on the child side that needs one -- so having none missing here is a real result, '
                    .'not a lesson with nothing to show.',
            );
        }

        usort(
            $unindexed,
            static fn (Constraint $a, Constraint $b): int => ($rows[$b->qualifiedName()] ?? 0) <=> ($rows[$a->qualifiedName()] ?? 0),
        );

        $worst = $unindexed[0];
        $worstRows = $rows[$worst->qualifiedName()] ?? 0;

        return new Observation(
            headline: count($unindexed).' foreign key(s) in this database have no covering index. The worst '
                ."is `{$worst->qualifiedName()}`, with ".number_format($worstRows).' row(s) that a parent '
                .'delete would have to scan in full.',
            columns: ['constraint', 'table', 'column(s)', 'references', 'rows in table'],
            rows: array_map(fn (Constraint $c): array => $this->toRow($c, $rows), $unindexed),
        );
    }

    public function tryIt(): string
    {
        return "select conname, conrelid::regclass as table, confrelid::regclass as references\n"
            ."from pg_constraint\n"
            ."where contype = 'f'\n"
            ."and not exists (\n"
            ."    select 1 from pg_index\n"
            ."    where indrelid = conrelid\n"
            ."    and indisvalid\n"
            ."    and (indkey::int2[])[0:cardinality(conkey) - 1] = conkey\n"
            .');';
    }

    private function createIndexStatement(Constraint $large): string
    {
        $columns = implode(', ', $large->columns);
        $name = str_replace('.', '_', $large->qualifiedName()).'_'.implode('_', $large->columns).'_idx';

        return "create index concurrently {$name} on {$large->qualifiedName()} ({$columns});";
    }

    /**
     * @param  list<TableProfile>  $profiles
     * @return array<string, int>
     */
    private function rowCounts(array $profiles): array
    {
        $rows = [];

        foreach ($profiles as $profile) {
            $rows[$profile->qualifiedName()] = $profile->liveTuples;
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $rows
     * @return list<string>
     */
    private function toRow(Constraint $constraint, array $rows): array
    {
        return [
            $constraint->name,
            $constraint->qualifiedName(),
            implode(', ', $constraint->columns),
            $constraint->referencedTable,
            number_format($rows[$constraint->qualifiedName()] ?? 0),
        ];
    }
}
