<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\Statements;
use Heyosseus\Vacuum\Values\Statement;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
    DB::statement('CREATE TABLE crates (id serial PRIMARY KEY, label text)');
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 5) i");
    DB::statement('SELECT pg_stat_statements_reset()');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
});

function statementsInstalled(): bool
{
    return DB::table('pg_extension')->where('extname', 'pg_stat_statements')->exists();
}

/**
 * Whether this connection may create the second role the aggregation test needs.
 * A developer pointing the suite at a database they do not own should see the
 * test skip, not fail.
 */
function canCreateRoles(): bool
{
    return (bool) DB::scalar('SELECT rolcreaterole OR rolsuper FROM pg_roles WHERE rolname = current_user');
}

function crateStatement(): ?Statement
{
    return collect(app(Statements::class)->slowest())
        ->first(fn (Statement $statement): bool => str_contains($statement->sql, 'FROM crates'));
}

/**
 * pg_stat_statements keeps one row per (userid, dbid, queryid, toplevel), not one
 * row per query shape. The same normalized statement run by two roles is two rows
 * carrying the same queryid — which is why the "slowest" list showed the query
 * twice, why the Filament model's queryid primary key was not unique, and why the
 * history snapshot's per-queryid metrics collided. Aggregating is the fix, and two
 * real roles is the only honest way to prove it.
 */
it('returns one row per queryid when the same query ran under two roles', function (): void {
    DB::statement('DROP ROLE IF EXISTS vacuum_probe');
    DB::statement("CREATE ROLE vacuum_probe LOGIN PASSWORD 'probe'");

    try {
        $database = config('database.connections.'.DB::getDefaultConnection().'.database');
        DB::statement('GRANT CONNECT ON DATABASE '.$database.' TO vacuum_probe');
        DB::statement('GRANT USAGE ON SCHEMA public TO vacuum_probe');
        DB::statement('GRANT SELECT ON crates TO vacuum_probe');

        DB::select("SELECT id FROM crates WHERE label = 'crate 1'");

        $config = config('database.connections.'.DB::getDefaultConnection());
        $other = new PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            'vacuum_probe',
            'probe',
        );
        $other->query("SELECT id FROM crates WHERE label = 'crate 1'")->fetchAll();
        unset($other);

        // The raw view really does hold the split rows: without this, the test
        // could pass against a server that never produced the condition at all.
        $raw = DB::select(
            "SELECT queryid, count(*) AS rows FROM pg_stat_statements
             WHERE query LIKE '%FROM crates%' AND query NOT LIKE '%pg_stat_%'
             GROUP BY queryid HAVING count(*) > 1",
        );

        expect($raw)->not->toBeEmpty('pg_stat_statements did not record the query under two roles');

        $found = collect(app(Statements::class)->slowest())
            ->filter(fn (Statement $statement): bool => str_contains($statement->sql, 'FROM crates'));

        expect($found)->toHaveCount(1);
    } finally {
        $database = config('database.connections.'.DB::getDefaultConnection().'.database');
        DB::statement('REVOKE ALL ON crates FROM vacuum_probe');
        DB::statement('REVOKE ALL ON SCHEMA public FROM vacuum_probe');
        DB::statement('REVOKE ALL ON DATABASE '.$database.' FROM vacuum_probe');
        DB::statement('DROP ROLE IF EXISTS vacuum_probe');
    }
})->skip(
    fn (): bool => ! statementsInstalled() || ! canCreateRoles(),
    'Needs pg_stat_statements and a role that may create roles.',
);

it('adds up the calls the same query made under every role', function (): void {
    DB::select("SELECT id FROM crates WHERE label = 'crate 2'");
    DB::select("SELECT id FROM crates WHERE label = 'crate 3'");

    // Two executions of one normalized shape, whoever ran them.
    expect(crateStatement())->not->toBeNull()
        ->and(crateStatement()->calls)->toBe(2);
})->skip(fn (): bool => ! statementsInstalled(), 'pg_stat_statements is not installed on this server.');

it('averages the mean over the summed calls rather than trusting one row', function (): void {
    DB::select("SELECT id FROM crates WHERE label = 'crate 4'");
    DB::select("SELECT id FROM crates WHERE label = 'crate 5'");

    $statement = crateStatement();

    // mean = total / calls, recomputed from the sums; a mean of means would be
    // wrong the moment the rows carried different call counts.
    expect($statement->meanMilliseconds)
        ->toEqualWithDelta($statement->totalMilliseconds / $statement->calls, 0.0001);
})->skip(fn (): bool => ! statementsInstalled(), 'pg_stat_statements is not installed on this server.');

it('keeps its own reading of the statistics out of the statistics', function (): void {
    app(Statements::class)->slowest();

    $found = collect(app(Statements::class)->slowest())
        ->filter(fn (Statement $statement): bool => str_contains($statement->sql, 'pg_stat_statements'));

    expect($found)->toBeEmpty();
})->skip(fn (): bool => ! statementsInstalled(), 'pg_stat_statements is not installed on this server.');

/**
 * busiest() exists because slowest() ranks by mean time, and a query run a
 * great many times but cheap on each call sits near the bottom of that
 * ordering rather than the top. Running the same crate lookup far more often
 * than a slower, unrelated one proves busiest() puts call count ahead of mean
 * time.
 *
 * This does not assert the crate lookup is first overall: PDO's own
 * DEALLOCATE bookkeeping is itself a statement pg_stat_statements records,
 * and how many of those a shared test connection has accumulated is not this
 * class's concern to control. What busiest() promises is that call count
 * decides the order it does control -- the crate lookup ranking ahead of the
 * far slower, once-run pg_sleep is exactly that.
 */
it('ranks statements by call count rather than by mean time', function (): void {
    for ($i = 0; $i < 5; $i++) {
        DB::select("SELECT id FROM crates WHERE label = 'crate 1'");
    }
    DB::select('SELECT pg_sleep(0.05)');

    $busiest = app(Statements::class)->busiest();

    $indexOf = static function (string $needle) use ($busiest): ?int {
        foreach ($busiest as $index => $statement) {
            if (str_contains($statement->sql, $needle)) {
                return $index;
            }
        }

        return null;
    };

    $crateIndex = $indexOf('FROM crates');
    $sleepIndex = $indexOf('pg_sleep');

    expect($crateIndex)->not->toBeNull()
        ->and($busiest[$crateIndex]->calls)->toBe(5)
        ->and($sleepIndex)->not->toBeNull()
        ->and($crateIndex)->toBeLessThan($sleepIndex);
})->skip(fn (): bool => ! statementsInstalled(), 'pg_stat_statements is not installed on this server.');
