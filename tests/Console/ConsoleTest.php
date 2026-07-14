<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Vacuum;
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
