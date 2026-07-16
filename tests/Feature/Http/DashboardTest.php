<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Vacuum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

it('turns a request away when nobody has said it may look', function (): void {
    // The default gate opens only in local, and the test suite is not local.
    $this->get('/vacuum')->assertForbidden();
});

it('answers a request the application has authorized', function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);

    $this->get('/vacuum')
        ->assertOk()
        ->assertSee('Vacuum');
});

it('lets the application refuse a request of its own accord', function (): void {
    Vacuum::auth(static fn (Request $request): bool => $request->query('key') === 'let-me-in');

    $this->get('/vacuum')->assertForbidden();
    $this->get('/vacuum?key=let-me-in')->assertOk();
});

it('forgets an authorization callback between requests of different tests', function (): void {
    // Guards the static: a callback left behind by the test above would open
    // this one, and every test after it, without anybody noticing.
    $this->get('/vacuum')->assertForbidden();
});

it('says which server it is looking at', function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);

    $this->get('/vacuum')->assertSee('PostgreSQL');
});

it('shows what the advisor found on the database it is pointed at', function (): void {
    // The whole chain, end to end: pg_stat_user_tables, through the rules, onto
    // the page. A structural assertion on an empty database would pass even if
    // the advisor were never asked.
    DB::statement('DROP TABLE IF EXISTS gadgets');
    DB::statement('CREATE TABLE gadgets (id serial PRIMARY KEY)');
    DB::insert('INSERT INTO gadgets SELECT generate_series(1, 5000)');
    DB::delete('DELETE FROM gadgets');
    flushStatistics();

    Vacuum::auth(static fn (Request $request): bool => true);

    $this->get('/vacuum')
        ->assertOk()
        ->assertSee('public.gadgets')
        ->assertSee('VACUUM ANALYZE "public"."gadgets";');

    DB::statement('DROP TABLE IF EXISTS gadgets');
});

it('says so plainly when it has nothing to complain about', function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);

    $this->get('/vacuum')
        ->assertSee('Nothing to report')
        ->assertSee('Grade A')
        ->assertSee('Nothing has been deducted');
});

it('cannot award a grade its own findings disagree with', function (): void {
    // The failure this guards against is the one these dashboards are famous for:
    // a green score sitting directly above a list of critical problems.
    DB::statement('DROP TABLE IF EXISTS gadgets');
    DB::statement('CREATE TABLE gadgets (id serial PRIMARY KEY)');
    DB::insert('INSERT INTO gadgets SELECT generate_series(1, 5000)');
    DB::delete('DELETE FROM gadgets');
    flushStatistics();

    Vacuum::auth(static fn (Request $request): bool => true);

    $this->get('/vacuum')
        ->assertSee('dead-tuples')
        ->assertDontSee('Grade A')
        ->assertSee('&minus;15', escape: false);

    DB::statement('DROP TABLE IF EXISTS gadgets');
});
