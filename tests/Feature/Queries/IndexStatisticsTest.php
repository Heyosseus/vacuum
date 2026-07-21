<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\IndexStatistics;
use Heyosseus\Vacuum\Values\IndexStatistic;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS pallets');
    DB::statement('CREATE TABLE pallets (id serial PRIMARY KEY, label text)');
    DB::statement('CREATE INDEX pallets_label_index ON pallets (label)');
    DB::insert("INSERT INTO pallets (label) SELECT 'pallet ' || i FROM generate_series(1, 5000) i");
    flushStatistics();
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS pallets');
});

function index(string $name): ?IndexStatistic
{
    return collect(app(IndexStatistics::class)->all())
        ->firstWhere(fn (IndexStatistic $index): bool => $index->name === $name);
}

it('reports an index no query has touched', function (): void {
    $index = index('pallets_label_index');

    expect($index?->scans)->toBe(0)
        ->and($index?->neverUsed())->toBeTrue()
        ->and($index?->table)->toBe('pallets')
        ->and($index?->bytes)->toBeGreaterThan(0);
});

it('counts the scans an index has served', function (): void {
    // The table is small enough that PostgreSQL would rather scan the whole
    // thing, so the planner has to be talked out of it.
    DB::statement('SET enable_seqscan = off');
    DB::select("SELECT id FROM pallets WHERE label = 'pallet 42'");
    flushStatistics();

    expect(index('pallets_label_index')?->scans)->toBeGreaterThan(0);
});

it('tells an index you added from a constraint the database is enforcing', function (): void {
    expect(index('pallets_label_index')?->constrains())->toBeFalse()
        ->and(index('pallets_pkey')?->primary)->toBeTrue()
        ->and(index('pallets_pkey')?->constrains())->toBeTrue();
});

it('reports an index a failed concurrent build left behind', function (): void {
    // The real thing, not a fabricated flag. A unique index built concurrently over
    // data that already holds duplicates fails on its second pass, and PostgreSQL
    // leaves the half-built index in place, marked invalid, maintained by every
    // write and usable by no query.
    DB::insert("INSERT INTO pallets (label) VALUES ('duplicate'), ('duplicate')");

    try {
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY pallets_label_unique ON pallets (label)');
    } catch (QueryException) {
        // Expected: this is how the invalid index comes to exist.
    }

    expect(index('pallets_label_index')?->valid)->toBeTrue()
        ->and(index('pallets_label_unique')?->valid)->toBeFalse();
});

it('leaves out the schemas the configuration ignores', function (): void {
    $schemas = collect(app(IndexStatistics::class)->all())
        ->map(fn (IndexStatistic $index): string => $index->schema);

    expect($schemas)->not->toContain('pg_catalog');
});

it('marks an exclusion constraint\'s index as one the database is enforcing', function (): void {
    // The whole of H5 in one relation. An exclusion constraint is backed by an
    // index that is neither unique nor primary, so constrains() -- which was
    // exactly indisprimary || indisunique -- said it was an ordinary index. An
    // unused one is then offered up with DROP INDEX CONCURRENTLY, and PostgreSQL
    // refuses: "cannot drop index ... because constraint ... requires it". The
    // flagship remediation failing in the reader's hands is worse than no advice.
    DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    DB::statement('DROP TABLE IF EXISTS bookings');
    DB::statement('CREATE TABLE bookings (id serial PRIMARY KEY, room int, during tstzrange)');
    DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap '
        .'EXCLUDE USING gist (room WITH =, during WITH &&)');
    flushStatistics();

    $index = index('bookings_no_overlap');

    expect($index)->not->toBeNull()
        ->and($index->unique)->toBeFalse()
        ->and($index->primary)->toBeFalse()
        ->and($index->constraintOwned)->toBeTrue()
        ->and($index->constrains())->toBeTrue();

    DB::statement('DROP TABLE IF EXISTS bookings');
})->skip(fn (): bool => ! DB::table('pg_available_extensions')->where('name', 'btree_gist')->exists(),
    'btree_gist is not available on this server');

it('reports an ordinary index as neither constraint-owned nor a partition child', function (): void {
    $index = index('pallets_label_index');

    expect($index)->not->toBeNull()
        ->and($index->constraintOwned)->toBeFalse()
        ->and($index->replicaIdentity)->toBeFalse()
        ->and($index->partitionChild)->toBeFalse()
        ->and($index->constrains())->toBeFalse();
});

it('marks an index attached to a partitioned index as one that cannot be dropped alone', function (): void {
    // A partition's index belongs to its parent: DROP INDEX on the child is
    // refused, and the advice has to know that before it offers one.
    DB::statement('DROP TABLE IF EXISTS crates');
    DB::statement('CREATE TABLE crates (id int, region text) PARTITION BY LIST (region)');
    DB::statement("CREATE TABLE crates_north PARTITION OF crates FOR VALUES IN ('north')");
    DB::statement('CREATE INDEX crates_region_index ON crates (region)');
    flushStatistics();

    $child = collect(app(IndexStatistics::class)->all())
        ->first(fn (IndexStatistic $index): bool => str_starts_with($index->name, 'crates_north_'));

    expect($child)->not->toBeNull()
        ->and($child->partitionChild)->toBeTrue()
        ->and($child->constrains())->toBeTrue();

    DB::statement('DROP TABLE IF EXISTS crates');
});
