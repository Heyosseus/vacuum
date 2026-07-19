<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Lessons\SoftDeletes;
use Heyosseus\Vacuum\Queries\Columns;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Values\Column;
use Heyosseus\Vacuum\Values\TableProfile;
use Illuminate\Support\Facades\DB;

/*
 * Two tables: one that carries a deleted_at column and one that does not, so
 * observe() has both a table it must name and a table it must not. Dropped
 * before and after every test since the test database is shared.
 */
beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS soft_deleting_orders');
    DB::statement('DROP TABLE IF EXISTS plain_products');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS soft_deleting_orders');
    DB::statement('DROP TABLE IF EXISTS plain_products');
});

/**
 * A TableProfile with every one of its ~25 required parameters zeroed out
 * except whatever the test actually cares about. TableProfile is final
 * readonly and cannot be mocked, so fork() can only be exercised against
 * values built this way. Named softDeleteProfile() rather than profile() or
 * forkProfile(): tests/Feature/Queries/TableProfilesTest.php already
 * declares a global profile() helper and tests/Unit/Learn/BranchSelectionTest.php
 * already declares forkProfile() -- a second declaration of either name is a
 * fatal redeclaration error the moment both files load in the same suite run.
 *
 * @param  array<string, mixed>  $overrides
 */
function softDeleteProfile(array $overrides = []): TableProfile
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

function softDeleteColumn(string $table, string $name, string $schema = 'public'): Column
{
    return new Column(
        schema: $schema,
        table: $table,
        name: $name,
        type: 'timestamp without time zone',
        nullable: true,
    );
}

it('names a table with a deleted_at column and not one without', function (): void {
    DB::statement('CREATE TABLE soft_deleting_orders (id serial PRIMARY KEY, deleted_at timestamp null)');
    DB::insert('INSERT INTO soft_deleting_orders (id) SELECT i FROM generate_series(1, 20) i');
    DB::statement('CREATE TABLE plain_products (id serial PRIMARY KEY, name text)');
    DB::insert("INSERT INTO plain_products (id, name) SELECT i, 'x' FROM generate_series(1, 20) i");
    flushStatistics();

    $observation = (new SoftDeletes(app(Columns::class), app(TableProfiles::class)))->observe();

    expect($observation->headline)->toContain('soft_deleting_orders')
        ->and($observation->columns)->toBe(['table', 'live rows', 'dead rows', 'dead share'])
        ->and($observation->rows)->not->toBeEmpty();

    $tableNames = array_column($observation->rows, 0);

    expect($tableNames)->toContain('public.soft_deleting_orders')
        ->and($tableNames)->not->toContain('public.plain_products');
});

it('says why there is nothing to show when no table has a deleted_at column', function (): void {
    DB::statement('CREATE TABLE plain_products (id serial PRIMARY KEY, name text)');
    flushStatistics();

    $observation = (new SoftDeletes(app(Columns::class), app(TableProfiles::class)))->observe();

    expect($observation->isEmpty())->toBeTrue()
        ->and($observation->headline)->not->toBeEmpty()
        ->and($observation->note)->not->toBeNull();
});

it('hands the reader a runnable statement for band three', function (): void {
    $sql = (new SoftDeletes(app(Columns::class), app(TableProfiles::class)))->tryIt();

    expect($sql)->toContain('information_schema.columns')
        ->and($sql)->toContain('deleted_at');
});

it('sends a large soft-deleting table to the partial-index branch', function (): void {
    $orders = softDeleteProfile(['name' => 'orders', 'liveTuples' => 50_000, 'deadTuples' => 100]);
    $columns = [softDeleteColumn('orders', 'deleted_at')];

    $tree = (new SoftDeletes(app(Columns::class), app(TableProfiles::class)))->fork($columns, [$orders]);

    expect($tree->branches[0]->landed)->toBe(['public.orders'])
        ->and($tree->branches[0]->fix)->toBe(
            'create index concurrently public_orders_live_idx on public.orders (id) where deleted_at is null;',
        )
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('sends a high dead-share soft-deleting table to the bloat branch with no fix', function (): void {
    $orders = softDeleteProfile(['name' => 'orders', 'liveTuples' => 100, 'deadTuples' => 500]);
    $columns = [softDeleteColumn('orders', 'deleted_at')];

    $tree = (new SoftDeletes(app(Columns::class), app(TableProfiles::class)))->fork($columns, [$orders]);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe(['public.orders'])
        ->and($tree->branches[1]->fix)->toBeNull()
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('sends a small soft-deleting table to the all-clear branch', function (): void {
    $orders = softDeleteProfile(['name' => 'orders', 'liveTuples' => 100, 'deadTuples' => 5]);
    $columns = [softDeleteColumn('orders', 'deleted_at')];

    $tree = (new SoftDeletes(app(Columns::class), app(TableProfiles::class)))->fork($columns, [$orders]);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe(['public.orders'])
        ->and($tree->branches[2]->fix)->toBeNull();
});

it('ignores a table with no deleted_at column entirely', function (): void {
    $products = softDeleteProfile(['name' => 'products', 'liveTuples' => 50_000, 'deadTuples' => 500]);
    $columns = [softDeleteColumn('orders', 'deleted_at')];

    $tree = (new SoftDeletes(app(Columns::class), app(TableProfiles::class)))->fork($columns, [$products]);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('renders all three branches when nothing landed on any of them', function (): void {
    $tree = (new SoftDeletes(app(Columns::class), app(TableProfiles::class)))->fork([], []);

    expect($tree->branches)->toHaveCount(3)
        ->and($tree->branches[0]->condition)->not->toBeEmpty()
        ->and($tree->branches[1]->condition)->not->toBeEmpty()
        ->and($tree->branches[2]->condition)->not->toBeEmpty();
});

it('describes itself for the curriculum', function (): void {
    $lesson = new SoftDeletes(app(Columns::class), app(TableProfiles::class));

    expect($lesson->slug())->toBe('soft-deletes')
        ->and($lesson->title())->toBe('What SoftDeletes costs')
        ->and($lesson->tier())->toBe(Heyosseus\Vacuum\Learn\Tier::Eloquent)
        ->and($lesson->after())->toBeNull()
        ->and($lesson->hook())->not->toBeEmpty()
        ->and($lesson->tree())->toBeInstanceOf(Heyosseus\Vacuum\Learn\Tree::class);
});
