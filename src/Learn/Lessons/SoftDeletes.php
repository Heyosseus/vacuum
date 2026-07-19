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
 * Shows a reader which of their own tables carry a deleted_at column, and
 * what that column is quietly doing to every query and every mass delete
 * against it.
 *
 * Recognition is by column name alone, the same convention {@see Columns}
 * documents itself on: this package never autoloads the host application's
 * models, so it cannot see the SoftDeletes trait itself, only the column it
 * leaves behind. A table with a deleted_at column that Eloquent never put
 * there would show up here too -- the catalog proves the column exists, not
 * that the trait wrote it -- and that honesty is worth stating plainly
 * rather than pretending this is a scan of PHP source.
 */
final readonly class SoftDeletes implements Lesson
{
    /** Enough tables to see the pattern without turning the page into a census. */
    private const int ROWS = 10;

    /**
     * The row count above which a missing partial index stops being a
     * rounding error. There is no number PostgreSQL will hand back for this
     * -- ten thousand live rows is cheap for a plain index to carry and
     * expensive for none of it -- so this is the same judgement call
     * {@see UnindexedForeignKeys::LARGE} makes, for the same reason: a
     * plausible line between "won't notice" and "will", not a measured one.
     */
    private const int LARGE = 10_000;

    /**
     * The dead-row share above which a table is carrying the aftermath of a
     * mass soft-delete rather than ordinary churn. This mirrors
     * {@see DeadTuples::POOR}: a fifth of a table already dead is well past
     * what autovacuum's default settings would tolerate for long on an
     * active table, and picking the same number as the lesson that already
     * teaches dead-tuple thresholds keeps the two pages from silently
     * disagreeing with each other.
     */
    private const float HIGH_DEAD_SHARE = 0.2;

    public function __construct(
        private Columns $columns,
        private TableProfiles $profiles,
    ) {}

    public function slug(): string
    {
        return 'soft-deletes';
    }

    public function title(): string
    {
        return 'What SoftDeletes costs';
    }

    public function tier(): Tier
    {
        return Tier::Eloquent;
    }

    public function hook(): string
    {
        return 'See what a deleted_at column is quietly doing to every query and every mass delete on your own tables.';
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
     * read-only executor, so nothing about a live query can be mocked, and
     * the one thing this fork must get right -- that a large soft-deleting
     * table, a bloated one, and a small harmless one are sent to three
     * different outcomes -- is precisely what a live database cannot be
     * relied on to demonstrate on demand.
     *
     * @param  list<Column>  $columns
     * @param  list<TableProfile>  $profiles
     */
    public function fork(array $columns, array $profiles): Tree
    {
        $names = $this->softDeletingNames($columns);

        $softDeleting = array_values(array_filter(
            $profiles,
            static fn (TableProfile $p): bool => in_array($p->qualifiedName(), $names, true),
        ));

        $large = array_values(array_filter(
            $softDeleting,
            static fn (TableProfile $p): bool => $p->liveTuples >= self::LARGE,
        ));
        usort($large, static fn (TableProfile $a, TableProfile $b): int => $b->liveTuples <=> $a->liveTuples);

        $bloated = array_values(array_filter(
            $softDeleting,
            static fn (TableProfile $p): bool => $p->deadTupleRatio() > self::HIGH_DEAD_SHARE,
        ));

        $small = array_values(array_filter(
            $softDeleting,
            static fn (TableProfile $p): bool => $p->liveTuples < self::LARGE && $p->deadTupleRatio() <= self::HIGH_DEAD_SHARE,
        ));

        return new Tree('Is SoftDeletes costing you on this table?', [
            new Branch(
                condition: 'The table already holds '.number_format(self::LARGE).' live rows or more.',
                outcome: 'Every query Eloquent sends to this table carries `where deleted_at is null`, whether '
                    .'the code says so or not. A plain index on deleted_at is the wrong shape for that '
                    .'predicate -- it indexes every row, dead ones included, and stores a timestamp almost '
                    .'nothing ever filters by. A partial index built with that same predicate is smaller, '
                    .'contains only live rows, and is the only one the planner needs.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $large),
                fix: $large === [] ? null : $this->createIndexStatement($large[0]),
            ),
            new Branch(
                condition: 'More than '.number_format(self::HIGH_DEAD_SHARE * 100).'% of the table is dead rows.',
                outcome: 'A mass soft-delete is not a DELETE -- it is an UPDATE of every matched row, and '
                    .'PostgreSQL never edits a row in place. Each one writes a whole new copy and leaves the '
                    .'old version behind as a dead tuple, so "deleting" a million rows this way makes the '
                    .'table bigger, not smaller, until vacuum reclaims what it left. See the dead-tuples '
                    .'lesson for whether autovacuum has actually had the chance to.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $bloated),
            ),
            new Branch(
                condition: 'The table has a deleted_at column but is still under '.number_format(self::LARGE).' rows.',
                outcome: 'Nothing to do here yet. A plain index on deleted_at costs almost nothing to carry '
                    .'at this size, and there is no meaningful amount of dead-row bloat for a mass delete to '
                    .'have left behind.',
                landed: array_map(static fn (TableProfile $p): string => $p->qualifiedName(), $small),
            ),
        ]);
    }

    public function observe(): Observation
    {
        $names = $this->softDeletingNames($this->columns->all());

        if ($names === []) {
            return new Observation(
                headline: 'No table in this database has a deleted_at column.',
                note: 'Vacuum recognises SoftDeletes by that column name alone -- it never loads the host '
                    ."application's models, so it cannot see the trait itself. Nothing here uses it, at "
                    .'least not under that column name.',
            );
        }

        $softDeleting = array_values(array_filter(
            $this->profiles->all(),
            static fn (TableProfile $p): bool => in_array($p->qualifiedName(), $names, true),
        ));

        // Unreachable on every PostgreSQL this package supports, and kept anyway.
        //
        // Reaching it needs a table that Columns can see and TableProfiles cannot.
        // Columns reads pg_class for relkind 'r' and 'p'; pg_stat_all_tables has
        // been a direct pg_class scan over 'r', 't', 'm' and 'p' since 14, so the
        // partitioned parent that used to fall through the gap no longer does --
        // verified against 17.5 rather than assumed, and 14 is the floor the
        // README promises.
        //
        // It stays because the line below indexes $softDeleting[0], and the two
        // queries are separate round trips: a table dropped between them would
        // turn a missing guard into an undefined index. A branch that cannot be
        // reached by a fixture but can be reached by a race is worth six lines.
        // @codeCoverageIgnoreStart
        if ($softDeleting === []) {
            return new Observation(
                headline: 'No table in this database has a deleted_at column.',
                note: 'Vacuum recognises SoftDeletes by that column name alone -- it never loads the host '
                    ."application's models, so it cannot see the trait itself. Nothing here uses it, at "
                    .'least not under that column name.',
            );
        }
        // @codeCoverageIgnoreEnd

        usort($softDeleting, static fn (TableProfile $a, TableProfile $b): int => $b->liveTuples <=> $a->liveTuples);

        $worst = $softDeleting[0];

        return new Observation(
            headline: count($softDeleting).' table(s) in this database carry a deleted_at column. The largest '
                ."is `{$worst->qualifiedName()}`, with ".number_format($worst->liveTuples).' live row(s).',
            columns: ['table', 'live rows', 'dead rows', 'dead share'],
            rows: array_map($this->toRow(...), array_slice($softDeleting, 0, self::ROWS)),
        );
    }

    public function tryIt(): string
    {
        return "select table_schema, table_name\n"
            ."from information_schema.columns\n"
            ."where column_name = 'deleted_at'\n"
            .'order by table_schema, table_name;';
    }

    /**
     * @param  list<Column>  $columns
     * @return list<string>
     */
    private function softDeletingNames(array $columns): array
    {
        return array_values(array_map(
            static fn (Column $c): string => $c->qualifiedName(),
            array_filter($columns, static fn (Column $c): bool => $c->name === 'deleted_at'),
        ));
    }

    private function createIndexStatement(TableProfile $table): string
    {
        $name = str_replace('.', '_', $table->qualifiedName()).'_live_idx';

        return "create index concurrently {$name} on {$table->qualifiedName()} (id) where deleted_at is null;";
    }

    /**
     * @return list<string>
     */
    private function toRow(TableProfile $profile): array
    {
        return [
            $profile->qualifiedName(),
            number_format($profile->liveTuples),
            number_format($profile->deadTuples),
            number_format($profile->deadTupleRatio() * 100, 1).'%',
        ];
    }
}
