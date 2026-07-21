<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Lessons\TimestampsAndHot;
use Heyosseus\Vacuum\Queries\Columns;
use Heyosseus\Vacuum\Queries\IndexStatistics;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Values\IndexStatistic;
use Heyosseus\Vacuum\Values\TableProfile;
use Illuminate\Support\Facades\DB;

/**
 * A table with updated_at and an index that names it -- the case this lesson
 * exists for -- next to a table with updated_at and no such index, so observe()
 * can be proven to name one and not the other on a live database.
 */
beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_hot_indexed');
    DB::statement('DROP TABLE IF EXISTS learn_hot_plain');

    DB::statement('CREATE TABLE learn_hot_indexed (id serial PRIMARY KEY, label text, updated_at timestamp)');
    DB::statement('CREATE INDEX learn_hot_indexed_updated_at_idx ON learn_hot_indexed (updated_at)');

    DB::statement('CREATE TABLE learn_hot_plain (id serial PRIMARY KEY, label text, updated_at timestamp)');

    DB::insert("INSERT INTO learn_hot_indexed (label, updated_at) SELECT 'x' || i, now() FROM generate_series(1, 200) i");
    DB::insert("INSERT INTO learn_hot_plain (label, updated_at) SELECT 'x' || i, now() FROM generate_series(1, 200) i");

    DB::update('UPDATE learn_hot_indexed SET updated_at = now()');
    DB::update('UPDATE learn_hot_plain SET label = label');

    flushStatistics();
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_hot_indexed');
    DB::statement('DROP TABLE IF EXISTS learn_hot_plain');
});

function timestampsLesson(): TimestampsAndHot
{
    return new TimestampsAndHot(app(Columns::class), app(IndexStatistics::class), app(TableProfiles::class));
}

/**
 * A TableProfile with every one of its ~25 required parameters zeroed out
 * except whatever a test actually cares about. TableProfile is final readonly
 * and cannot be mocked, so fork() can only be exercised against values built
 * this way. Named timestampsProfile() rather than profile() or forkProfile(),
 * both of which are already declared by other test files loaded in the same
 * suite run.
 *
 * @param  array<string, mixed>  $overrides
 */
function timestampsProfile(array $overrides = []): TableProfile
{
    $defaults = [
        'schema' => 'public',
        'name' => 'orders',
        'liveTuples' => 0,
        'deadTuples' => 0,
        'modificationsSinceAnalyze' => 0,
        'xidAge' => 0,
        'mxidAge' => 0,
        'heapBytes' => 0,
        'indexBytes' => 0,
        'toastBytes' => 0,
        'totalBytes' => 0,
        'sequentialScans' => 0,
        'sequentialTuplesRead' => 0,
        'indexScans' => 0,
        'indexTuplesFetched' => 0,
        'inserts' => 0,
        'updates' => 0,
        'hotUpdates' => 0,
        'deletes' => 0,
        'lastVacuum' => null,
        'lastAutovacuum' => null,
        'lastAnalyze' => null,
        'lastAutoanalyze' => null,
        'vacuumScaleFactor' => 0.2,
        'vacuumThreshold' => 50,
        'analyzeScaleFactor' => 0.1,
        'analyzeThreshold' => 50,
        'tuned' => false,
        'fillfactor' => null,
    ];

    return new TableProfile(...array_merge($defaults, $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function timestampsIndexStatistic(array $overrides = []): IndexStatistic
{
    $defaults = [
        'schema' => 'public',
        'table' => 'orders',
        'name' => 'orders_updated_at_idx',
        'scans' => 0,
        'bytes' => 0,
        'unique' => false,
        'primary' => false,
        'valid' => true,
        'countingSince' => null,
    ];

    return new IndexStatistic(...array_merge($defaults, $overrides));
}

it('names the table with an index on updated_at and not the plain one', function (): void {
    $observation = timestampsLesson()->observe();

    expect($observation->isEmpty())->toBeFalse()
        ->and($observation->columns)->toBe(['table', 'index on updated_at', 'updates', 'HOT share', 'fillfactor']);

    $tables = implode(' ', array_map(static fn (array $row): string => $row[0], $observation->rows));

    expect($tables)->toContain('learn_hot_indexed')
        ->and($tables)->not->toContain('learn_hot_plain')
        ->and($observation->headline)->toContain('learn_hot_indexed');
});

it('says so plainly when nothing in the database indexes updated_at', function (): void {
    DB::statement('DROP INDEX learn_hot_indexed_updated_at_idx');
    flushStatistics();

    $observation = timestampsLesson()->observe();

    // Other schemas on this shared connection may still carry their own index on
    // updated_at, so this only proves the sentence shape holds when there is
    // nothing left to name -- not that this specific database has none at all.
    if ($observation->isEmpty()) {
        expect($observation->headline)->toContain('No table')
            ->and($observation->note)->toContain('good case');
    } else {
        $tables = implode(' ', array_map(static fn (array $row): string => $row[0], $observation->rows));

        expect($tables)->not->toContain('learn_hot_indexed')
            ->and($tables)->not->toContain('learn_hot_plain');
    }
});

it('hands the reader a runnable statement for band three', function (): void {
    $sql = timestampsLesson()->tryIt();

    expect($sql)->toBeString()
        ->and($sql)->toContain('pg_stat_user_tables')
        ->and($sql)->toContain('updated_at');
});

it('describes itself for the curriculum', function (): void {
    $lesson = timestampsLesson();

    expect($lesson->slug())->toBe('timestamps-and-hot')
        ->and($lesson->title())->toBe('The index on updated_at that doubled your writes')
        ->and($lesson->tier())->toBe(Heyosseus\Vacuum\Learn\Tier::Eloquent)
        ->and($lesson->after())->toBe('fillfactor')
        ->and($lesson->hook())->not->toBeEmpty();
});

it('delegates tree() to fork() using its own live data', function (): void {
    $tree = timestampsLesson()->tree();

    expect($tree->question)->not->toBeEmpty()
        ->and($tree->branches)->toHaveCount(2);
});

/**
 * Two tables with the same poor HOT share and different causes. The ratio alone
 * cannot tell them apart, which is exactly why the branch has to -- built rather
 * than measured, since a live database offers no guarantee it demonstrates both
 * at once.
 */
it('sends a table with an index on updated_at and a poor HOT share to the index branch', function (): void {
    $indexed = timestampsProfile(['name' => 'sessions', 'updates' => 1000, 'hotUpdates' => 50]);

    $tree = timestampsLesson()->fork(
        [$indexed],
        ['public.sessions' => timestampsIndexStatistic(['table' => 'sessions', 'name' => 'sessions_updated_at_idx', 'scans' => 0])],
    );

    expect($tree->branches[0]->landed)->toBe(['public.sessions'])
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[0]->fix)->toBe('drop index concurrently public.sessions_updated_at_idx;');
});

it('offers no drop fix when the index on updated_at has been scanned', function (): void {
    $indexed = timestampsProfile(['name' => 'sessions', 'updates' => 1000, 'hotUpdates' => 50]);

    $tree = timestampsLesson()->fork(
        [$indexed],
        ['public.sessions' => timestampsIndexStatistic(['table' => 'sessions', 'name' => 'sessions_updated_at_idx', 'scans' => 12])],
    );

    expect($tree->branches[0]->landed)->toBe(['public.sessions'])
        ->and($tree->branches[0]->fix)->toBeNull();
});

it('sends a table with a poor HOT share and no index on updated_at to the other branch', function (): void {
    $notIndexed = timestampsProfile(['name' => 'orders', 'updates' => 1000, 'hotUpdates' => 50]);

    $tree = timestampsLesson()->fork([$notIndexed], []);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe(['public.orders']);
});

it('leaves a healthy table off the tree entirely', function (): void {
    $healthy = timestampsProfile(['name' => 'logs', 'updates' => 1000, 'hotUpdates' => 990]);

    $tree = timestampsLesson()->fork(
        [$healthy],
        ['public.logs' => timestampsIndexStatistic(['table' => 'logs', 'name' => 'logs_updated_at_idx'])],
    );

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([]);
});

it('ignores a table that has never been updated', function (): void {
    $neverUpdated = timestampsProfile(['name' => 'audit_log', 'updates' => 0, 'hotUpdates' => 0]);

    $tree = timestampsLesson()->fork(
        [$neverUpdated],
        ['public.audit_log' => timestampsIndexStatistic(['table' => 'audit_log', 'name' => 'audit_log_updated_at_idx'])],
    );

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([]);
});

/**
 * tablesWithUpdatedAtIndex() walks every index once and keeps only the first
 * one it finds per table, via `if (isset($matches[$table])) { continue; }`.
 * A table normally has at most one index whose name mentions updated_at, so
 * nothing in the fixtures above ever reaches that guard -- reaching it needs
 * a table that carries two such indexes at once, so the loop visits the same
 * table a second time after already having matched it once.
 */
it('keeps only the first index it finds when a table carries two that both mention updated_at', function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_hot_double');
    DB::statement('CREATE TABLE learn_hot_double (id serial PRIMARY KEY, label text, updated_at timestamp)');
    DB::statement('CREATE INDEX learn_hot_double_updated_at_a_idx ON learn_hot_double (updated_at)');
    DB::statement('CREATE INDEX learn_hot_double_updated_at_b_idx ON learn_hot_double (label, updated_at)');

    DB::insert("INSERT INTO learn_hot_double (label, updated_at) SELECT 'x' || i, now() FROM generate_series(1, 200) i");
    DB::update('UPDATE learn_hot_double SET updated_at = now()');

    flushStatistics();

    try {
        $observation = timestampsLesson()->observe();

        $tableNames = array_column($observation->rows, 0);
        $matchesForDouble = array_filter($tableNames, static fn (string $name): bool => $name === 'public.learn_hot_double');

        // Whichever of the two indexes the query happened to return first, the
        // table is only reported once -- the second index the loop meets for
        // it is discarded by the guard rather than overwriting or duplicating
        // the first match.
        expect($matchesForDouble)->toHaveCount(1);

        $indexNames = array_column($observation->rows, 1);
        $reportedIndex = array_values(array_filter(
            $observation->rows,
            static fn (array $row): bool => $row[0] === 'public.learn_hot_double',
        ))[0][1];

        expect($indexNames)->toContain($reportedIndex)
            ->and(in_array($reportedIndex, ['learn_hot_double_updated_at_a_idx', 'learn_hot_double_updated_at_b_idx'], true))->toBeTrue();
    } finally {
        DB::statement('DROP TABLE IF EXISTS learn_hot_double');
    }
});

/**
 * tablesWithUpdatedAtIndex() walks every index in the database, including the
 * ones belonging to tables that have no updated_at column at all, and skips
 * those before it ever looks at the index name.
 *
 * Nothing else in this file reaches that skip: every table the fixtures build
 * carries updated_at, so on a database holding only them the loop never meets
 * an index it has to pass over. A shared development database happens to be
 * full of such tables and hides this; a clean CI database is not, which is
 * where it showed. Hence a table that deliberately has no timestamps at all.
 */
it('passes over an index belonging to a table with no updated_at column', function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_hot_no_timestamp');
    DB::statement('CREATE TABLE learn_hot_no_timestamp (id serial PRIMARY KEY, label text)');
    DB::statement('CREATE INDEX learn_hot_no_timestamp_label_idx ON learn_hot_no_timestamp (label)');

    DB::insert("INSERT INTO learn_hot_no_timestamp (label) SELECT 'x' || i FROM generate_series(1, 200) i");
    DB::update("UPDATE learn_hot_no_timestamp SET label = label || '!'");

    flushStatistics();

    try {
        $observation = timestampsLesson()->observe();

        $tables = array_column($observation->rows, 0);

        expect($tables)->not->toContain('public.learn_hot_no_timestamp')
            ->and($observation->headline)->not->toContain('learn_hot_no_timestamp');
    } finally {
        DB::statement('DROP TABLE IF EXISTS learn_hot_no_timestamp');
    }
});

it('renders both branches when nothing landed on either', function (): void {
    $tree = timestampsLesson()->fork([], []);

    expect($tree->branches)->toHaveCount(2)
        ->and($tree->branches[0]->condition)->not->toBeEmpty()
        ->and($tree->branches[1]->condition)->not->toBeEmpty();
});
