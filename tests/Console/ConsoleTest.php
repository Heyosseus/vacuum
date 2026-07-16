<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Vacuum;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);

    DB::statement('DROP TABLE IF EXISTS tins');
    DB::statement('CREATE TABLE tins (id serial PRIMARY KEY, label text)');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS tins');
});

it('shows the console to somebody the application let in', function (): void {
    $this->get('/vacuum/console')->assertOk()->assertSee('Console');
});

it('types a statement into the box when a finding sends one', function (): void {
    $this->get('/vacuum/console?statement='.urlencode('SELECT n_dead_tup FROM pg_stat_user_tables'))
        ->assertOk()
        ->assertSee('SELECT n_dead_tup FROM pg_stat_user_tables', escape: false);
});

it('does not run the statement a link arrived with', function (): void {
    // Following a link must never execute anything. The query is put in the box and
    // left there: the person who arrives decides whether it runs.
    DB::insert("INSERT INTO tins (label) VALUES ('anchovies')");

    $this->get('/vacuum/console?statement='.urlencode('SELECT label FROM tins'))
        ->assertOk()
        ->assertDontSee('anchovies');
});

it('runs a select and shows what came back', function (): void {
    DB::insert("INSERT INTO tins (label) VALUES ('anchovies'), ('sardines')");

    $this->post('/vacuum/console', ['statement' => 'SELECT label FROM tins ORDER BY label'])
        ->assertOk()
        ->assertSee('anchovies')
        ->assertSee('sardines');
});

it('turns away a statement that would write, and says why', function (): void {
    $this->post('/vacuum/console', ['statement' => 'DELETE FROM tins'])
        ->assertOk()
        ->assertSee('reads');
});

it('lets postgresql refuse the write the guard let through', function (): void {
    // The guard reads the first word of the statement, so a data-modifying CTE
    // walks straight past it. This is why the guard was never the safety boundary:
    // the transaction is READ ONLY and PostgreSQL itself rejects the write.
    $smuggled = "WITH written AS (INSERT INTO tins (label) VALUES ('smuggled') RETURNING *) SELECT * FROM written";

    $this->post('/vacuum/console', ['statement' => $smuggled])
        ->assertOk()
        ->assertSee('read-only');

    expect(DB::table('tins')->count())->toBe(0);
});

it('stops a statement that runs for longer than it is allowed', function (): void {
    config()->set('vacuum.console.timeout', 100);

    $this->post('/vacuum/console', ['statement' => 'SELECT pg_sleep(3)'])
        ->assertOk()
        ->assertSee('timeout');
});

it('shows only as many rows as it was told to', function (): void {
    config()->set('vacuum.console.max_rows', 3);

    $this->post('/vacuum/console', ['statement' => 'SELECT generate_series(1, 100) AS n'])
        ->assertOk()
        ->assertSee('first 3');
});

it('asks for a statement before it runs one', function (): void {
    $this->post('/vacuum/console', ['statement' => ''])->assertSessionHasErrors('statement');
});

/**
 * Vacuum's read-only guarantee is a READ ONLY transaction. Inside a transaction
 * that is already open, beginTransaction() only opens a savepoint, and a savepoint
 * cannot be made read-only — so the executor refuses rather than run something it
 * cannot promise anything about. That refusal is a sentence written for a person,
 * and the console prints it rather than turning it into a 500.
 *
 * Capabilities is stubbed here because it is a singleton probed from the same
 * connection: left to resolve, it would raise the same refusal from inside the
 * container while the controller was still being built, which is a different
 * failure from the one under test. A long-lived process — Octane, a queue worker —
 * is where this shape occurs for real, the probe having succeeded long before the
 * request that finds a transaction open.
 */
function capabilitiesAlreadyProbed(): void
{
    app()->instance(Capabilities::class, new Capabilities(
        serverVersion: 170_005,
        extensions: ['pg_stat_statements'],
        settings: ['shared_preload_libraries' => 'pg_stat_statements'],
        readsAllStatistics: true,
    ));
}

it('explains itself rather than crashing when a transaction is already open', function (): void {
    capabilitiesAlreadyProbed();

    DB::beginTransaction();

    try {
        $this->post('/vacuum/console', ['statement' => 'SELECT 1'])
            ->assertOk()
            ->assertSee('read-only guarantee would not hold');
    } finally {
        DB::rollBack();
    }
});

it('explains itself rather than crashing when the connection is not postgresql', function (): void {
    capabilitiesAlreadyProbed();

    config()->set('database.connections.sqlite_probe', ['driver' => 'sqlite', 'database' => ':memory:']);
    config()->set('vacuum.connection', 'sqlite_probe');

    $this->post('/vacuum/console', ['statement' => 'SELECT 1'])
        ->assertOk()
        ->assertSee('inspects PostgreSQL only');
});
