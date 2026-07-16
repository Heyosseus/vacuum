<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\BloatEstimates;
use Heyosseus\Vacuum\Values\BloatEstimate;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
    DB::statement('CREATE TABLE crates (id serial PRIMARY KEY, label text)');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
});

function crates(): ?BloatEstimate
{
    return collect(app(BloatEstimates::class)->all())
        ->firstWhere(fn (BloatEstimate $table): bool => $table->name === 'crates');
}

/**
 * Whether PostgreSQL is holding "unknown" rather than a row count for the table:
 * reltuples = -1, the value it uses since 14 for a relation nothing has analyzed.
 */
function unknownRowCount(): bool
{
    return (float) DB::scalar("SELECT reltuples FROM pg_class WHERE relname = 'crates'") === -1.0;
}

it('finds next to no bloat in a table nothing has deleted from', function (): void {
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 20000) i");
    DB::statement('ANALYZE crates');

    // Not zero: the estimate rounds a partly filled page up to a whole one, so a
    // perfectly packed table still reports a page it is not quite using. This is
    // why the advisor asks how many bytes are wasted and not merely whether any are.
    expect(crates()?->bloatRatio())->toBeLessThan(0.05);
});

it('estimates the space a table is holding on to after a delete', function (): void {
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 20000) i");
    DB::statement('ANALYZE crates');

    // The pages stay allocated to the table until a vacuum reclaims them, so the
    // rows are gone and the size is not.
    DB::delete('DELETE FROM crates WHERE id > 2000');
    DB::statement('ANALYZE crates');

    $crates = crates();

    expect($crates?->bloatBytes)->toBeGreaterThan(0)
        ->and($crates?->bloatRatio())->toBeGreaterThan(0.5)
        ->and($crates?->realBytes)->toBeGreaterThan($crates?->bloatBytes);
});

it('reports the fillfactor a table was created with', function (): void {
    DB::statement('ALTER TABLE crates SET (fillfactor = 70)');
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 1000) i");
    DB::statement('ANALYZE crates');

    expect(crates()?->fillfactor)->toBe(70);
});

it('assumes the fillfactor postgres would have used when none was set', function (): void {
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 1000) i");
    DB::statement('ANALYZE crates');

    expect(crates()?->fillfactor)->toBe(100);
});

it('says nothing at all about a table it has no statistics for', function (): void {
    // An empty table has no rows in pg_stats, so there is nothing to reconstruct
    // the expected size from. Reporting zero bloat would be a guess wearing the
    // clothes of a measurement.
    DB::statement('ANALYZE crates');

    expect(crates())->toBeNull();
});

/**
 * Since PostgreSQL 14, pg_class.reltuples is -1 for a table nothing has analyzed
 * — "unknown", which is a different fact from zero. Feeding -1 into the page
 * arithmetic estimates a row count that was never measured, so the table is left
 * out entirely: an estimate built on an unknown is not an estimate.
 */
it('says nothing about a table nothing has ever analyzed', function (): void {
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 20000) i");

    // Rows on disk, and reltuples still -1: pg_class is only updated by VACUUM,
    // ANALYZE or CREATE INDEX, and none of them has run.
    expect(unknownRowCount())->toBeTrue()
        ->and(crates())->toBeNull();
});

/**
 * The same unknown, reached by the path where it survives the is_na guard:
 * TRUNCATE resets reltuples to -1 but leaves the rows in pg_stats behind, so the
 * table looks analyzed to every other test in the query and still has no row
 * count anybody measured.
 */
it('says nothing about a truncated table whose statistics outlived its rows', function (): void {
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 20000) i");
    DB::statement('ANALYZE crates');
    DB::statement('TRUNCATE crates');

    expect(unknownRowCount())->toBeTrue()
        ->and((int) DB::scalar("SELECT count(*) FROM pg_stats WHERE tablename = 'crates'"))->toBeGreaterThan(0)
        ->and(crates())->toBeNull();
});

it('leaves out the schemas the configuration ignores', function (): void {
    $schemas = collect(app(BloatEstimates::class)->all())
        ->map(fn (BloatEstimate $table): string => $table->schema);

    expect($schemas)->not->toContain('pg_catalog');
});
