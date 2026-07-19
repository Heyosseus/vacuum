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
use Illuminate\Support\Str;

/**
 * Shows a reader which of their own tables pay the OFFSET tax that
 * {@see \Illuminate\Database\Eloquent\Builder::chunk()} quietly charges, and
 * which primary key they have on hand to key {@see chunkById()} off instead.
 *
 * chunk() compiles to `LIMIT ? OFFSET ?`, walked forward one page at a time.
 * PostgreSQL has no way to skip the first n rows of a sorted result other
 * than producing every one of them and throwing them away, so the tenth
 * chunk costs ten times what the first one did and the hundredth chunk
 * costs a hundred times as much -- a job over a large table degrades
 * quadratically rather than linearly. chunkById() and lazyById() ask a
 * different question instead: "give me rows with id greater than the last
 * one I saw," which an index answers in the same handful of page reads no
 * matter how deep into the table it is asked.
 */
final readonly class ChunkingLargeTables implements Lesson
{
    /**
     * The row count above which the OFFSET tax stops being noise and starts
     * being minutes. There is no number PostgreSQL will hand back for this --
     * a hundred-row table pays the same quadratic cost in principle, it is
     * just too small to notice -- so this is a judgement call about where
     * "you would feel it" plausibly begins, not a measured threshold.
     */
    private const int LARGE = 100_000;

    /** Enough tables to see the pattern without turning the page into a census. */
    private const int ROWS = 10;

    public function __construct(
        private TableProfiles $profiles,
        private Constraints $constraints,
    ) {}

    public function slug(): string
    {
        return 'chunking-large-tables';
    }

    public function title(): string
    {
        return 'Why chunk() gets slower as it goes';
    }

    public function tier(): Tier
    {
        return Tier::Eloquent;
    }

    public function hook(): string
    {
        return 'See which of your tables make chunk() pay more for every page it walks.';
    }

    public function after(): ?string
    {
        return null;
    }

    public function tree(): Tree
    {
        return $this->fork($this->profiles->all(), $this->constraints->all());
    }

    /**
     * The judgement, separated from the fetch so it can be exercised against
     * profiles and constraints that were built rather than measured.
     *
     * Public deliberately, for the same reason as {@see Fillfactor::fork()}:
     * TableProfiles and Constraints are both final readonly classes wrapping
     * a read-only executor, so nothing about a live query can be mocked, and
     * the one thing this fork must get right -- that a large table with a
     * usable primary key and a large table without one are sent to different
     * outcomes -- is precisely what a live database cannot be relied on to
     * demonstrate on demand.
     *
     * @param  list<TableProfile>  $profiles
     * @param  list<Constraint>  $constraints
     */
    public function fork(array $profiles, array $constraints): Tree
    {
        $keys = $this->primaryKeyColumns($constraints);

        $large = array_values(array_filter(
            $profiles,
            static fn (TableProfile $p): bool => $p->liveTuples >= self::LARGE,
        ));

        $keyed = array_values(array_filter(
            $large,
            fn (TableProfile $p): bool => isset($keys[$p->qualifiedName()]),
        ));

        $unkeyed = array_values(array_filter(
            $large,
            fn (TableProfile $p): bool => ! isset($keys[$p->qualifiedName()]),
        ));

        $small = array_values(array_filter(
            $profiles,
            static fn (TableProfile $p): bool => $p->liveTuples < self::LARGE,
        ));

        return new Tree('Which iteration method should this table use?', [
            new Branch(
                condition: 'The table holds '.number_format(self::LARGE).' rows or more and has a single-column primary key.',
                outcome: 'chunkById() seeks straight to the next id with an index lookup instead of counting '
                    .'past every row already processed, so chunk one and chunk fifty thousand cost the same.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $keyed),
                fix: $keyed === [] ? null : $this->chunkByIdStatement($keyed[0], $keys[$keyed[0]->qualifiedName()]),
            ),
            new Branch(
                condition: 'The table holds '.number_format(self::LARGE).' rows or more and has no usable single-column primary key.',
                outcome: 'chunkById() has nothing to key on. This is the awkward case: order by a unique '
                    .'indexed column and page on that by hand, or add a single-column key the chunker can use.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $unkeyed),
            ),
            new Branch(
                condition: 'The table holds fewer than '.number_format(self::LARGE).' rows.',
                outcome: 'It does not matter at this size -- the deepest OFFSET a small table can produce is '
                    .'still cheap to throw away. chunk() is fine here and reads more plainly than chunkById().',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $small),
            ),
        ]);
    }

    public function observe(): Observation
    {
        $profiles = $this->profiles->all();

        if ($profiles === []) {
            return new Observation(
                headline: 'This database has no tables to measure yet.',
                note: 'Come back once there is data to chunk through.',
            );
        }

        $keys = $this->primaryKeyColumns($this->constraints->all());

        $sorted = $profiles;
        usort($sorted, static fn (TableProfile $a, TableProfile $b): int => $b->liveTuples <=> $a->liveTuples);

        $largest = $sorted[0];
        $offsetCost = number_format(intdiv($largest->liveTuples, 2));

        return new Observation(
            headline: "`{$largest->qualifiedName()}` is the largest table here, at "
                .number_format($largest->liveTuples).' row(s). An OFFSET halfway into it has to produce and '
                ."discard roughly {$offsetCost} rows before it can hand back a single one you asked for.",
            columns: ['table', 'rows', 'primary key', 'sequential scans'],
            rows: array_map(fn (TableProfile $p): array => $this->toRow($p, $keys), array_slice($sorted, 0, self::ROWS)),
        );
    }

    public function tryIt(): ?string
    {
        $profiles = $this->profiles->all();

        if ($profiles === []) {
            return null;
        }

        $sorted = $profiles;
        usort($sorted, static fn (TableProfile $a, TableProfile $b): int => $b->liveTuples <=> $a->liveTuples);

        $table = $sorted[0]->qualifiedName();

        return "explain analyze select * from {$table} order by id offset 100000 limit 10;";
    }

    /**
     * The table's own single-column primary key, keyed by qualifiedName().
     *
     * A composite primary key is deliberately left out: chunkById() orders and
     * compares on one column, and a composite key has no single value to hand
     * it, so a table with only a composite key gets sent down the same
     * "nothing usable" branch as a table with no key at all.
     *
     * @param  list<Constraint>  $constraints
     * @return array<string, string>
     */
    private function primaryKeyColumns(array $constraints): array
    {
        $keys = [];

        foreach ($constraints as $constraint) {
            if ($constraint->kind === 'p' && count($constraint->columns) === 1) {
                $keys[$constraint->qualifiedName()] = $constraint->columns[0];
            }
        }

        return $keys;
    }

    /**
     * The reader's own table name turned into the Eloquent call they would
     * actually write, so the fix reads as their code rather than as a
     * template. Str::singular() is Laravel's own guess at a model name from
     * a table name -- the same guess Eloquent itself makes in reverse when
     * it infers a table from a model -- so it is wrong exactly as often as
     * the convention it is standing in for is wrong.
     */
    private function chunkByIdStatement(TableProfile $table, string $column): string
    {
        $model = Str::studly(Str::singular($table->name));
        $variable = Str::camel($table->name);

        return "{$model}::chunkById(1000, fn (\$$variable) => ..., column: '{$column}');";
    }

    /**
     * @param  array<string, string>  $keys
     * @return list<string>
     */
    private function toRow(TableProfile $profile, array $keys): array
    {
        return [
            $profile->qualifiedName(),
            number_format($profile->liveTuples),
            $keys[$profile->qualifiedName()] ?? '—',
            number_format($profile->sequentialScans),
        ];
    }
}
