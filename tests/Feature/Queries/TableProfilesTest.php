<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\TableProfiles;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
    DB::statement('CREATE TABLE crates (id serial PRIMARY KEY, label text, note text)');
    DB::statement('SELECT pg_stat_force_next_flush()');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
});

function profile(): ?Heyosseus\Vacuum\Values\TableProfile
{
    return app(TableProfiles::class)->find('public', 'crates');
}

it('has nothing to say about a table that is not there', function (): void {
    // A table somebody dropped, or a URL somebody typed. Neither is an error.
    expect(app(TableProfiles::class)->find('public', 'no_such_table'))->toBeNull();
});

it('measures the four sizes separately', function (): void {
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 500) i");
    DB::statement('SELECT pg_stat_force_next_flush()');

    $crates = profile();

    expect($crates->heapBytes)->toBeGreaterThan(0)
        ->and($crates->indexBytes)->toBeGreaterThan(0)
        ->and($crates->totalBytes)->toBeGreaterThanOrEqual($crates->heapBytes + $crates->indexBytes)
        // A table of short text has a TOAST relation, but nothing has been pushed
        // out to it, so its size is not part of what the rows cost.
        ->and($crates->toastBytes)->toBeLessThan($crates->heapBytes);
});

it('counts how the table was read', function (): void {
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 500) i");
    DB::select('SELECT count(*) FROM crates');
    DB::statement('SELECT pg_stat_force_next_flush()');

    expect(profile()->sequentialScans)->toBeGreaterThan(0)
        ->and(profile()->sequentialShare())->toBe(1.0);
});

it('sees the fillfactor decide whether an update rewrites the indexes', function (): void {
    // This is the whole HOT lesson, measured rather than asserted. Rows inserted into
    // a table with the default fillfactor fill their pages to the brim, so an update
    // has nowhere to put the new version except another page -- and every index has
    // to be rewritten to point at it. Leaving 30% of each page free changes the
    // answer, and nothing else about the table changes at all.
    DB::statement('DROP TABLE IF EXISTS roomy');
    DB::statement('CREATE TABLE roomy (id serial PRIMARY KEY, label text) WITH (fillfactor = 70)');
    DB::insert("INSERT INTO roomy (label) SELECT 'x' || i FROM generate_series(1, 200) i");
    DB::insert("INSERT INTO crates (label) SELECT 'x' || i FROM generate_series(1, 200) i");

    DB::update("UPDATE crates SET label = label || '!'");
    DB::update("UPDATE roomy SET label = label || '!'");
    DB::statement('SELECT pg_stat_force_next_flush()');

    $packed = profile();
    $roomy = app(TableProfiles::class)->find('public', 'roomy');

    DB::statement('DROP TABLE roomy');

    expect($packed->updates)->toBe(200)
        ->and($roomy->updates)->toBe(200)
        ->and($packed->hotUpdateRatio())->toBeLessThan(0.1)
        ->and($roomy->hotUpdateRatio())->toBeGreaterThan(0.4);
});

it('works out the number of dead rows autovacuum is waiting for', function (): void {
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 1000) i");
    DB::statement('SELECT pg_stat_force_next_flush()');

    // PostgreSQL's defaults: 50 dead rows, plus a fifth of the table.
    expect(profile()->vacuumsAt())->toBe(50 + (int) (0.2 * 1000))
        ->and(profile()->tuned)->toBeFalse();
});

it('lets a table overrule the server about when it is vacuumed', function (): void {
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 1000) i");
    DB::statement('ALTER TABLE crates SET (autovacuum_vacuum_scale_factor = 0.01)');
    DB::statement('SELECT pg_stat_force_next_flush()');

    // The reloption wins, which is the whole point of reading both.
    expect(profile()->vacuumScaleFactor)->toBe(0.01)
        ->and(profile()->vacuumsAt())->toBe(50 + 10)
        ->and(profile()->tuned)->toBeTrue();
});

it('does not call a fillfactor an autovacuum setting', function (): void {
    DB::statement('ALTER TABLE crates SET (fillfactor = 80)');
    DB::statement('SELECT pg_stat_force_next_flush()');

    // The table has a storage parameter, but autovacuum is still the server's.
    expect(profile()->tuned)->toBeFalse();
});
