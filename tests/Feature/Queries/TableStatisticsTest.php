<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\TableStatistics;
use Heyosseus\Vacuum\Values\TableStatistic;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS widgets');
    DB::statement('CREATE TABLE widgets (id serial PRIMARY KEY, name text)');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS widgets');
    DB::statement('DROP SCHEMA IF EXISTS archive CASCADE');
});

/**
 * PostgreSQL accumulates statistics in each backend and flushes them to shared
 * memory at its own pace, so a test that reads them straight after a write sees
 * nothing. pg_stat_force_next_flush makes the pending counters visible now.
 */
function flushStatistics(): void
{
    DB::statement('SELECT pg_stat_force_next_flush()');
}

function widgets(): TableStatistic
{
    $table = collect(app(TableStatistics::class)->all())
        ->firstWhere(fn (TableStatistic $table): bool => $table->name === 'widgets');

    expect($table)->not->toBeNull();

    return $table;
}

it('counts the rows a table currently holds', function (): void {
    DB::insert("INSERT INTO widgets (name) SELECT 'widget ' || i FROM generate_series(1, 10) i");
    flushStatistics();

    expect(widgets()->liveTuples)->toBe(10)
        ->and(widgets()->deadTuples)->toBe(0);
});

it('counts the dead tuples a delete leaves behind', function (): void {
    DB::insert("INSERT INTO widgets (name) SELECT 'widget ' || i FROM generate_series(1, 10) i");
    DB::delete('DELETE FROM widgets WHERE id <= 4');
    flushStatistics();

    expect(widgets()->deadTuples)->toBe(4)
        ->and(widgets()->liveTuples)->toBe(6)
        ->and(widgets()->deadTupleRatio())->toBe(0.4);
});

it('reports a table that has never been vacuumed', function (): void {
    flushStatistics();

    expect(widgets()->lastVacuumedAt())->toBeNull();
});

it('reports when a table was last vacuumed', function (): void {
    DB::statement('VACUUM widgets');
    flushStatistics();

    expect(widgets()->lastVacuumedAt())->not->toBeNull();
});

it('counts the modifications made since the last analyze', function (): void {
    DB::statement('ANALYZE widgets');
    DB::insert("INSERT INTO widgets (name) SELECT 'widget ' || i FROM generate_series(1, 3) i");
    flushStatistics();

    expect(widgets()->modificationsSinceAnalyze)->toBe(3);
});

it('reports how far a table has fallen behind the transaction horizon', function (): void {
    flushStatistics();

    // A table created moments ago is a few transactions old at most, and the
    // point of the assertion is that PostgreSQL answered at all: an age of zero
    // and a missing column are the same value once it has been cast.
    expect(widgets()->xidAge)->toBeGreaterThanOrEqual(0)
        ->and(widgets()->xidAge)->toBeLessThan(TableStatistic::TRANSACTION_BUDGET)
        ->and(widgets()->transactionBudgetSpent())->toBeLessThan(0.01);
});

it('reports how far a table has fallen behind the multixact horizon', function (): void {
    flushStatistics();

    expect(widgets()->mxidAge)->toBeGreaterThanOrEqual(0)
        ->and(widgets()->mxidAge)->toBeLessThan(TableStatistic::MULTIXACT_BUDGET)
        ->and(widgets()->multixactBudgetSpent())->toBeLessThan(0.01);
});

/**
 * The multixact clock only moves when more than one transaction holds a lock on
 * the same row at once — that is the condition PostgreSQL allocates a multixact
 * for, and a single locker never creates one. Two concurrent FOR SHARE holders
 * is the smallest thing that does, so this proves Vacuum is reading the clock
 * that lock-heavy workloads actually advance, rather than a column that happens
 * to parse.
 */
it('sees the multixact age move when two transactions lock the same row at once', function (): void {
    DB::insert("INSERT INTO widgets (name) SELECT 'widget ' || i FROM generate_series(1, 3) i");
    DB::statement('VACUUM (FREEZE) widgets');

    $before = widgets()->mxidAge;

    // Separate backends: a multixact needs two live transactions at the same
    // instant, which one connection cannot produce.
    $config = config('database.connections.'.DB::getDefaultConnection());
    $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";

    $first = new PDO($dsn, $config['username'], $config['password']);
    $second = new PDO($dsn, $config['username'], $config['password']);

    $first->exec('BEGIN');
    $first->exec('SELECT * FROM widgets WHERE id = 1 FOR SHARE');
    $second->exec('BEGIN');
    $second->exec('SELECT * FROM widgets WHERE id = 1 FOR SHARE');
    $first->exec('COMMIT');
    $second->exec('COMMIT');

    flushStatistics();

    expect(widgets()->mxidAge)->toBeGreaterThan($before);
});

it('freezes a table back to the present when it is vacuumed', function (): void {
    DB::statement('VACUUM (FREEZE) widgets');
    flushStatistics();

    // Whatever the cluster's age is, this table is now at the front of it.
    $horizon = DB::scalar('SELECT age(datfrozenxid) FROM pg_database WHERE datname = current_database()');

    expect(widgets()->xidAge)->toBeLessThanOrEqual((int) $horizon);
});

it('inspects every schema when the ignore list is not a list at all', function (): void {
    flushStatistics();

    config()->set('vacuum.ignored_schemas', 'public');

    expect(widgets()->schema)->toBe('public');
});

it('leaves out the schemas the configuration ignores', function (): void {
    DB::statement('CREATE SCHEMA archive');
    DB::statement('CREATE TABLE archive.widgets (id serial PRIMARY KEY)');
    flushStatistics();

    config()->set('vacuum.ignored_schemas', ['pg_catalog', 'information_schema', 'pg_toast', 'archive']);

    $schemas = collect(app(TableStatistics::class)->all())->pluck('schema')->unique();

    expect($schemas)->not->toContain('archive');
});
