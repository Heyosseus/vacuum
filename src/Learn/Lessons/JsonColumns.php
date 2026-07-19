<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn\Lessons;

use Heyosseus\Vacuum\Learn\Branch;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Learn\Observation;
use Heyosseus\Vacuum\Learn\Tier;
use Heyosseus\Vacuum\Learn\Tree;
use Heyosseus\Vacuum\Queries\Columns;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Values\Column;
use Heyosseus\Vacuum\Values\TableProfile;

/**
 * Shows a reader every json and jsonb column in their own database, and why
 * a plain index on one of them almost never does what they expect.
 *
 * An Eloquent `$casts` entry of 'array' or 'json' puts a JSON document in a
 * column, but Laravel does not choose between `json` and `jsonb` for you --
 * the migration that created the column did, and the two behave nothing
 * alike. Recognition here is by column type alone: this package never
 * autoloads the host application's models, so it cannot see the `$casts`
 * array itself, only the type PostgreSQL actually stored. A `jsonb` column
 * nobody bothered to cast in the model looks identical from here to one that
 * is cast and read on every request.
 */
final readonly class JsonColumns implements Lesson
{
    /** Enough columns to see the pattern without turning the page into a census. */
    private const int ROWS = 10;

    /**
     * The row count above which a missing index on a jsonb column stops being a
     * rounding error. There is no number PostgreSQL will hand back for this --
     * a sequential scan over a jsonb column is cheap on a small table and
     * expensive on a large one, and "large" is a judgement call rather than a
     * measured threshold. Ten thousand rows matches the same call
     * {@see UnindexedForeignKeys::LARGE} and {@see SoftDeletes::LARGE} already
     * make, for the same reason: a plausible line between "won't notice" and
     * "will", kept consistent across the lessons a reader is likely to read
     * back to back.
     */
    private const int LARGE = 10_000;

    public function __construct(
        private Columns $columns,
        private TableProfiles $profiles,
    ) {}

    public function slug(): string
    {
        return 'json-columns';
    }

    public function title(): string
    {
        return 'json, jsonb, and the index that is not there';
    }

    public function tier(): Tier
    {
        return Tier::Eloquent;
    }

    public function hook(): string
    {
        return 'A plain index on a jsonb column does not do what you think it does. See what would.';
    }

    public function after(): ?string
    {
        return null;
    }

    public function tree(): Tree
    {
        return $this->fork($this->columns->all(), $this->profiles->all());
    }

    /**
     * The judgement, separated from the fetch so it can be exercised against
     * columns and profiles that were built rather than queried.
     *
     * Public deliberately, for the same reason as {@see Fillfactor::fork()}:
     * Columns and TableProfiles are both final readonly classes wrapping a
     * read-only executor, so nothing about a live query can be mocked, and the
     * one thing this fork must get right -- that a plain json column, a jsonb
     * column on a large table, and a jsonb column on a small table are sent to
     * three different outcomes -- is precisely what a live database cannot be
     * relied on to demonstrate side by side on demand.
     *
     * @param  list<Column>  $columns
     * @param  list<TableProfile>  $profiles
     */
    public function fork(array $columns, array $profiles): Tree
    {
        $profileByTable = [];
        foreach ($profiles as $profile) {
            $profileByTable[$profile->qualifiedName()] = $profile;
        }

        $plainJson = array_values(array_filter(
            $columns,
            static fn (Column $c): bool => $c->type === 'json',
        ));

        $jsonb = array_values(array_filter(
            $columns,
            static fn (Column $c): bool => $c->type === 'jsonb',
        ));

        $largeJsonb = array_values(array_filter(
            $jsonb,
            fn (Column $c): bool => $this->rowsFor($c, $profileByTable) >= self::LARGE,
        ));

        $smallJsonb = array_values(array_filter(
            $jsonb,
            fn (Column $c): bool => $this->rowsFor($c, $profileByTable) < self::LARGE,
        ));

        return new Tree('How do you actually query this document?', [
            new Branch(
                condition: 'The column is plain json, not jsonb.',
                outcome: 'json stores the raw text you sent and reparses the whole thing on every single '
                    .'access, even to read one key. It cannot be indexed for a lookup inside the document -- '
                    .'the only thing a plain index on it can ever match is the entire document, byte for '
                    .'byte. Converting it to jsonb is the fix, and it is a rewrite of the whole table under a '
                    .'lock, so plan it like one.',
                landed: array_map($this->landedName(...), $plainJson),
                fix: $plainJson === [] ? null : $this->convertStatement($plainJson[0]),
            ),
            new Branch(
                condition: 'The column is jsonb, on a table of '.number_format(self::LARGE).' rows or more.',
                outcome: 'jsonb is stored parsed, but a plain B-tree on the column still only indexes the '
                    .'whole document as one value -- it helps a query for the exact document and nothing '
                    .'that looks inside it. A GIN index lets `data @> \'{"status":"active"}\'` and `data ? '
                    .'\'status\'` use an index; a B-tree on one extracted expression is smaller and faster '
                    .'when every query asks for the same key.',
                landed: array_map($this->landedName(...), $largeJsonb),
                fix: $largeJsonb === [] ? null : $this->ginStatement($largeJsonb[0]),
            ),
            new Branch(
                condition: 'The column is jsonb, on a table under '.number_format(self::LARGE).' rows.',
                outcome: 'Nothing to do here yet. A sequential scan over a small table, jsonb column and all, '
                    .'is cheaper than maintaining a GIN index that only pays for itself once a query has '
                    .'many rows to skip.',
                landed: array_map($this->landedName(...), $smallJsonb),
            ),
        ]);
    }

    public function observe(): Observation
    {
        $columns = $this->columns->all();
        $profiles = $this->profiles->all();

        $profileByTable = [];
        foreach ($profiles as $profile) {
            $profileByTable[$profile->qualifiedName()] = $profile;
        }

        $jsonColumns = array_values(array_filter(
            $columns,
            static fn (Column $c): bool => $c->type === 'json' || $c->type === 'jsonb',
        ));

        if ($jsonColumns === []) {
            return new Observation(
                headline: 'No column in this database is json or jsonb.',
                note: 'This application stores no JSON documents in the database, at least not under either '
                    .'of those two types. That is a real finding, not a missing feature -- there is nothing '
                    .'here for this lesson to warn about.',
            );
        }

        $plainCount = count(array_filter($jsonColumns, static fn (Column $c): bool => $c->type === 'json'));

        usort(
            $jsonColumns,
            fn (Column $a, Column $b): int => $this->sortKey($a, $profileByTable) <=> $this->sortKey($b, $profileByTable),
        );

        return new Observation(
            headline: count($jsonColumns).' json or jsonb column(s) in this database, '.$plainCount
                .' of them plain json -- the ones worth converting to jsonb.',
            columns: ['table', 'column', 'type', 'rows'],
            rows: array_map(
                fn (Column $c): array => $this->toRow($c, $profileByTable),
                array_slice($jsonColumns, 0, self::ROWS),
            ),
        );
    }

    public function tryIt(): string
    {
        return "select table_schema, table_name, column_name, data_type\n"
            ."from information_schema.columns\n"
            ."where data_type in ('json', 'jsonb')\n"
            .'order by table_schema, table_name;';
    }

    /**
     * The order rows appear in: json before jsonb, since a plain json column
     * on a big table is the most actionable row on the page, then by row
     * count descending within each type.
     *
     * @param  array<string, TableProfile>  $profileByTable
     * @return array{0: int, 1: int} The type rank, then the negated row count, so a
     *                               plain ascending sort puts json first and the
     *                               largest table first within each type.
     */
    private function sortKey(Column $c, array $profileByTable): array
    {
        return [$c->type === 'json' ? 0 : 1, -$this->rowsFor($c, $profileByTable)];
    }

    /**
     * @param  array<string, TableProfile>  $profileByTable
     */
    private function rowsFor(Column $c, array $profileByTable): int
    {
        $profile = $profileByTable[$c->qualifiedName()] ?? null;

        return $profile instanceof TableProfile ? $profile->liveTuples : 0;
    }

    private function landedName(Column $c): string
    {
        return $c->qualifiedName().'.'.$c->name;
    }

    /**
     * @param  array<string, TableProfile>  $profileByTable
     * @return list<string>
     */
    private function toRow(Column $c, array $profileByTable): array
    {
        return [
            $c->qualifiedName(),
            $c->name,
            $c->type,
            number_format($this->rowsFor($c, $profileByTable)),
        ];
    }

    /**
     * Rewrites the column in place. Named USING because a bare type change is not
     * enough for PostgreSQL to know how to get from text-shaped json to jsonb --
     * the cast has to be spelled out even though it is the obvious one.
     */
    private function convertStatement(Column $c): string
    {
        return 'alter table '.$c->qualifiedName().' alter column '.$c->name
            .' type jsonb using '.$c->name.'::jsonb;';
    }

    private function ginStatement(Column $c): string
    {
        $name = str_replace('.', '_', $c->qualifiedName()).'_'.$c->name.'_gin_idx';

        return "create index concurrently {$name} on {$c->qualifiedName()} using gin ({$c->name});";
    }
}
