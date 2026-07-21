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

it('says so plainly when no table, index or session has anything wrong with it', function (): void {
    // The configuration rules judge the server itself rather than any table, so
    // they still have an opinion here, and the findings panel is expected to
    // carry that entry rather than sit empty.
    //
    // The condition is arranged rather than assumed. The obvious server-level
    // finding to lean on is unpatched-server, but whether it fires depends on
    // the minor release the server happens to be running: a developer's machine
    // drifts behind and reports it, while CI pulls the postgres:N image, which
    // is always the latest minor, and reports nothing. Asserting on it makes
    // this test pass or fail on how recently somebody ran apt upgrade.
    //
    // track_io_timing is a fact this test can state instead of hope for -- but it
    // has to be stated to the *server*, which is the whole lesson of the audit
    // finding this test was rewritten for. The configuration rules read
    // pg_settings.reset_val, so a session-level SET no longer reaches them, and
    // that is correct: a SET is what Vacuum's own executor does to its
    // connection, and an audit that could be moved by one is auditing itself.
    //
    // ALTER DATABASE is the smallest lever that changes what a session would
    // reset to. It applies to sessions opened afterwards, so the connection is
    // purged before the request and the setting removed again at the end.
    $database = DB::getDatabaseName();

    DB::statement("ALTER DATABASE \"{$database}\" SET track_io_timing = off");
    DB::purge();

    Vacuum::auth(static fn (Request $request): bool => true);

    try {
        $this->get('/vacuum')
            ->assertDontSee('Nothing to report')
            ->assertSee('io-timing-off');
    } finally {
        DB::statement("ALTER DATABASE \"{$database}\" RESET track_io_timing");
        DB::purge();
    }
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
