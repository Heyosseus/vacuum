<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Learn\Lessons;

use Heyosseus\Vacuum\Learn\Branch;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Learn\Observation;
use Heyosseus\Vacuum\Learn\Tier;
use Heyosseus\Vacuum\Learn\Tree;
use Heyosseus\Vacuum\Queries\Statements;
use Heyosseus\Vacuum\Values\Capabilities;
use Heyosseus\Vacuum\Values\Statement;

/**
 * Shows a reader the one thing every N+1 explainer on the internet skips: what
 * the loop looks like from the database's side of the connection.
 *
 * The application sees a loop: `foreach ($orders as $order) { $order->customer
 * ->name; }` runs one query per iteration. The database never sees a loop --
 * it sees a single prepared statement, `select * from "customers" where "id" =
 * $1`, executed a very large number of times, each execution individually
 * fast. That is precisely the signature pg_stat_statements keeps: enormous
 * `calls`, trivial `mean_exec_time`, and a `total_exec_time` built entirely
 * out of volume rather than out of any one execution being slow. A dashboard
 * that sorts by mean time -- {@see Statements::slowest()} -- will never
 * surface it, because nothing about any single call is slow. Only sorting by
 * calls -- {@see Statements::busiest()} -- puts it at the top.
 */
final readonly class NPlusOne implements Lesson
{
    /** Enough statements to see the pattern without turning the page into a census. */
    private const int ROWS = 10;

    /**
     * The character length a statement is shown at before it is cut off with
     * an ellipsis. Long enough to show the shape of the WHERE clause that
     * matters for this lesson, short enough that a ten-row table does not
     * become a wall of SQL.
     */
    private const int PREVIEW_LENGTH = 80;

    /**
     * The call count above which a statement is "running a very large number
     * of times" rather than merely often.
     *
     * There is no threshold PostgreSQL will hand back for this -- calls is a
     * lifetime counter since the last reset, with no notion of a request or a
     * time window attached to it -- so this is a judgement call about where a
     * count stops looking like ordinary repeated access and starts looking
     * like one parent row generating one call each. One thousand is chosen
     * because it is comfortably more than a single page of results could
     * plausibly generate by itself (a paginated list rarely shows more than a
     * few hundred rows at once), while staying low enough to catch a loop
     * over a modest collection rather than only the extreme cases. A
     * genuinely busy hot path -- the current user's settings row, looked up
     * on every request -- can clear this number too without being a loop at
     * all, which is exactly the distinction {@see self::fork()} exists to draw.
     */
    private const int VERY_MANY_CALLS = 1_000;

    public function __construct(
        private Statements $statements,
        private Capabilities $capabilities,
    ) {}

    public function slug(): string
    {
        return 'n-plus-one';
    }

    public function title(): string
    {
        return 'What N+1 looks like from the database';
    }

    public function tier(): Tier
    {
        return Tier::Eloquent;
    }

    public function hook(): string
    {
        return 'Find the statement your own database ran the most, and see why a slow-query dashboard would never have shown it to you.';
    }

    public function after(): string
    {
        return 'unindexed-foreign-keys';
    }

    public function tree(): Tree
    {
        return $this->fork($this->capabilities->tracksStatements() ? $this->statements->busiest() : []);
    }

    /**
     * The judgement, separated from the fetch so it can be exercised against
     * statements that were built rather than queried.
     *
     * Public deliberately, for the same reason as {@see Fillfactor::fork()}:
     * Statements is a final readonly class wrapping a read-only executor, so
     * nothing about a live query can be mocked, and the one thing this fork
     * must get right -- that a single-row key lookup called relentlessly and
     * a genuinely hot statement called just as relentlessly are sent to
     * different outcomes -- is precisely what a live database cannot be
     * relied on to demonstrate on demand.
     *
     * @param  list<Statement>  $statements
     */
    public function fork(array $statements): Tree
    {
        $veryCalled = array_values(array_filter(
            $statements,
            static fn (Statement $s): bool => $s->calls >= self::VERY_MANY_CALLS,
        ));

        $loopShaped = array_values(array_filter(
            $veryCalled,
            fn (Statement $s): bool => $this->looksLikeSingleRowKeyLookup($s->sql),
        ));

        $hotPath = array_values(array_filter(
            $veryCalled,
            fn (Statement $s): bool => ! $this->looksLikeSingleRowKeyLookup($s->sql),
        ));

        return new Tree('You have a statement running a very large number of times. Is it N+1?', [
            new Branch(
                condition: 'It looks up a single row by primary or foreign key -- `where "id" = $1` or '
                    .'`where "customer_id" = $1` -- and it has run a very large number of times.',
                outcome: 'This is the shape of a loop in application code: one parent, `$order->customer`, '
                    .'evaluated once per row of a collection Eloquent already had in memory. The fix is not '
                    .'SQL, because there is nothing wrong with the statement itself -- it is PHP: eager-load '
                    .'the relation so one query per parent becomes one query for all of them. '
                    .'`Order::with(\'customer\')->get()` in place of `Order::all()` followed by '
                    .'`$order->customer` inside a loop.',
                landed: array_map($this->toLanded(...), $loopShaped),
            ),
            new Branch(
                condition: 'It has run a very large number of times, but is not a single-row lookup by key.',
                outcome: 'This is a genuinely hot path rather than a loop -- something the application asks '
                    .'for constantly regardless of how many rows are in play. Eager loading has nothing to '
                    .'attach to here; look at caching the result, or at whether the access pattern itself '
                    .'needs to run this often.',
                landed: array_map($this->toLanded(...), $hotPath),
            ),
        ]);
    }

    public function observe(): Observation
    {
        if (! $this->capabilities->tracksStatements()) {
            return new Observation(
                headline: 'This database is not keeping track of the queries it runs.',
                note: 'This lesson reads pg_stat_statements, which is not installed or not active on this '
                    .'server -- the normal state on many managed providers (RDS, Cloud SQL, Supabase, Neon) '
                    .'until it is switched on deliberately. Nothing below can be shown until '
                    .'`CREATE EXTENSION pg_stat_statements;` has run and the library is listed in '
                    .'shared_preload_libraries, which needs a restart to take effect.',
            );
        }

        return $this->toObservation($this->statements->busiest());
    }

    /**
     * The rendering half of {@see self::observe()}, separated from the fetch
     * for the same reason as {@see self::fork()}: Statements wraps a live
     * read-only executor and cannot be made to hand back an empty result on
     * demand, so the empty-rows guard below has no other way to be reached
     * deterministically in a test.
     *
     * Private rather than public, unlike fork() -- nothing about the two
     * branches here needs to be exercised as part of proving a live database
     * behaves correctly, only as part of proving the formatting is right.
     *
     * @param  list<Statement>  $statements
     */
    private function toObservation(array $statements): Observation
    {
        if ($statements === []) {
            return new Observation(
                headline: 'pg_stat_statements is active, but this database has not recorded a single statement yet.',
                note: 'Run some queries against the application and reload this page -- there is nothing to '
                    .'rank until the server has something in its counters.',
            );
        }

        usort($statements, static fn (Statement $a, Statement $b): int => $b->calls <=> $a->calls);

        $busiest = $statements[0];

        return new Observation(
            headline: '`'.$this->preview($busiest->sql).'` ran '.number_format($busiest->calls).' time(s), '
                .'averaging '.number_format($busiest->meanMilliseconds, 3).' ms per call. Individually that is '
                .'fast; '.number_format($busiest->totalMilliseconds, 1).' ms of total time was spent on it '
                .'anyway, built entirely out of how often it ran rather than out of any single call being slow.',
            columns: ['statement', 'calls', 'mean time', 'total time'],
            rows: array_map($this->toRow(...), array_slice($statements, 0, self::ROWS)),
        );
    }

    public function tryIt(): string
    {
        return "select query, calls, mean_exec_time, total_exec_time\n"
            .'from pg_stat_statements'."\n"
            .'where dbid = (select oid from pg_database where datname = current_database())'."\n"
            .'order by calls desc'."\n"
            .'limit 10;';
    }

    /**
     * Whether a normalized statement has the shape of a single-row lookup by
     * primary or foreign key: exactly one placeholder, tested against a
     * column whose name is `id` or ends in `_id`.
     *
     * This is a heuristic over normalized SQL text, not a parse of the query
     * plan, and it is wrong in both directions on purpose rather than by
     * accident of a rule nobody stated:
     *
     *   - It can miss a real single-row lookup written with a differently
     *     named key column (`select * from sessions where token = $1`), or
     *     one that filters on more than the key (`... and tenant_id = $2`
     *     adds a second placeholder and the match is refused).
     *   - It can be fooled by a column that merely ends in `id` after an
     *     underscore without being a key at all, or by a composite-key table
     *     where the parameterized column is only half the story.
     *   - A single-placeholder, single-row lookup that clears the call
     *     threshold is not proof of a loop by itself -- it is only a shape
     *     consistent with one. {@see self::fork()} does not claim otherwise:
     *     the branch it lands on says "this is the shape of a loop", not
     *     "this is a loop", because the text alone cannot tell the two apart
     *     from a statement that is legitimately looked up constantly.
     *
     * The one thing it leans on that is not a guess: pg_stat_statements
     * normalises every literal into a `$1`, `$2`, ... placeholder, which is
     * exactly why a hundred thousand executions of the same loop collapse
     * into one row with one queryid in the first place.
     */
    private function looksLikeSingleRowKeyLookup(string $sql): bool
    {
        if (preg_match_all('/\$\d+/', $sql) !== 1) {
            return false;
        }

        if (preg_match('/where\s+(?:"?[a-z_][a-z0-9_]*"?\.)?"?([a-z_][a-z0-9_]*)"?\s*=\s*\$1\b/i', $sql, $matches) !== 1) {
            return false;
        }

        return (bool) preg_match('/^(id|[a-z0-9]+_id)$/i', $matches[1]);
    }

    private function toLanded(Statement $statement): string
    {
        return $this->preview($statement->sql).' ('.number_format($statement->calls).' calls)';
    }

    /**
     * @return list<string>
     */
    private function toRow(Statement $statement): array
    {
        return [
            $this->preview($statement->sql),
            number_format($statement->calls),
            number_format($statement->meanMilliseconds, 3).' ms',
            number_format($statement->totalMilliseconds, 1).' ms',
        ];
    }

    /**
     * A statement cut to a display-friendly length, because pg_stat_statements
     * keeps the whole normalized query text and a ten-row table has no room
     * for it.
     */
    private function preview(string $sql): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        return mb_strlen($sql) > self::PREVIEW_LENGTH
            ? mb_substr($sql, 0, self::PREVIEW_LENGTH).'…'
            : $sql;
    }
}
