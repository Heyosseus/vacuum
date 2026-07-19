<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Lessons\JsonColumns;
use Heyosseus\Vacuum\Queries\Columns;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Values\Column;
use Heyosseus\Vacuum\Values\TableProfile;
use Illuminate\Support\Facades\DB;

/**
 * A table with both a json and a jsonb column, next to a table with neither,
 * so observe() has one table it must name for each type and one it must not
 * name at all.
 */
beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_json_documents');
    DB::statement('DROP TABLE IF EXISTS learn_json_plain');

    DB::statement(
        'CREATE TABLE learn_json_documents ('
        .'id serial PRIMARY KEY, '
        .'legacy_payload json, '
        .'payload jsonb'
        .')'
    );
    DB::insert("INSERT INTO learn_json_documents (legacy_payload, payload) SELECT '{}', '{}' FROM generate_series(1, 20)");

    DB::statement('CREATE TABLE learn_json_plain (id serial PRIMARY KEY, name text)');
    DB::insert("INSERT INTO learn_json_plain (name) SELECT 'x' || i FROM generate_series(1, 20) i");

    flushStatistics();
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_json_documents');
    DB::statement('DROP TABLE IF EXISTS learn_json_plain');
});

function jsonColumnsLesson(): JsonColumns
{
    return new JsonColumns(app(Columns::class), app(TableProfiles::class));
}

/**
 * A TableProfile with every one of its ~25 required parameters zeroed out
 * except whatever a test actually cares about. TableProfile is final readonly
 * and cannot be mocked, so fork() can only be exercised against values built
 * this way. Named jsonColumnsProfile() rather than profile() or forkProfile(),
 * both of which are already declared by other test files loaded in the same
 * suite run.
 *
 * @param  array<string, mixed>  $overrides
 */
function jsonColumnsProfile(array $overrides = []): TableProfile
{
    $defaults = [
        'schema' => 'public',
        'name' => 'documents',
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

function jsonColumnsColumn(string $table, string $name, string $type, string $schema = 'public'): Column
{
    return new Column(
        schema: $schema,
        table: $table,
        name: $name,
        type: $type,
        nullable: true,
    );
}

it('names both the json and jsonb columns with their real types and not a column-free table', function (): void {
    $observation = jsonColumnsLesson()->observe();

    expect($observation->isEmpty())->toBeFalse()
        ->and($observation->columns)->toBe(['table', 'column', 'type', 'rows']);

    $tableNames = array_column($observation->rows, 0);
    $columnNames = array_column($observation->rows, 1);
    $types = array_column($observation->rows, 2);

    expect($tableNames)->toContain('public.learn_json_documents')
        ->and($tableNames)->not->toContain('public.learn_json_plain')
        ->and($columnNames)->toContain('legacy_payload', 'payload')
        ->and($types)->toContain('json', 'jsonb')
        ->and($observation->headline)->not->toBeEmpty();
});

it('says so plainly when no column in the database is json or jsonb', function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_json_documents');
    flushStatistics();

    $observation = jsonColumnsLesson()->observe();

    // Other schemas on this shared connection may still carry their own json or
    // jsonb column, so this only proves the sentence shape holds when there is
    // nothing left to name -- not that this specific database has none at all.
    if ($observation->isEmpty()) {
        expect($observation->headline)->toContain('No column')
            ->and($observation->note)->toContain('stores no JSON documents');
    } else {
        $tableNames = array_column($observation->rows, 0);

        expect($tableNames)->not->toContain('public.learn_json_documents');
    }
});

it('hands the reader a runnable statement for band three', function (): void {
    $sql = jsonColumnsLesson()->tryIt();

    expect($sql)->toContain('information_schema.columns')
        ->and($sql)->toContain('json')
        ->and($sql)->toContain('jsonb');
});

it('describes itself for the curriculum', function (): void {
    $lesson = jsonColumnsLesson();

    expect($lesson->slug())->toBe('json-columns')
        ->and($lesson->title())->toBe('json, jsonb, and the index that is not there')
        ->and($lesson->tier())->toBe(Heyosseus\Vacuum\Learn\Tier::Eloquent)
        ->and($lesson->after())->toBeNull()
        ->and($lesson->hook())->not->toBeEmpty()
        ->and($lesson->tree())->toBeInstanceOf(Heyosseus\Vacuum\Learn\Tree::class);
});

it('delegates tree() to fork() using its own live data', function (): void {
    $tree = jsonColumnsLesson()->tree();

    expect($tree->question)->not->toBeEmpty()
        ->and($tree->branches)->toHaveCount(3);
});

it('sends a plain json column to the conversion branch with a real fix', function (): void {
    $columns = [jsonColumnsColumn('orders', 'payload', 'json')];
    $profiles = [jsonColumnsProfile(['name' => 'orders', 'liveTuples' => 500])];

    $tree = jsonColumnsLesson()->fork($columns, $profiles);

    expect($tree->branches[0]->landed)->toBe(['public.orders.payload'])
        ->and($tree->branches[0]->fix)->toBe(
            'alter table public.orders alter column payload type jsonb using payload::jsonb;',
        )
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('sends a jsonb column on a large table to the gin-index branch with a real fix', function (): void {
    $columns = [jsonColumnsColumn('orders', 'payload', 'jsonb')];
    $profiles = [jsonColumnsProfile(['name' => 'orders', 'liveTuples' => 50_000])];

    $tree = jsonColumnsLesson()->fork($columns, $profiles);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe(['public.orders.payload'])
        ->and($tree->branches[1]->fix)->toBe(
            'create index concurrently public_orders_payload_gin_idx on public.orders using gin (payload);',
        )
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('sends a jsonb column on a small table to the all-clear branch with no fix', function (): void {
    $columns = [jsonColumnsColumn('orders', 'payload', 'jsonb')];
    $profiles = [jsonColumnsProfile(['name' => 'orders', 'liveTuples' => 100])];

    $tree = jsonColumnsLesson()->fork($columns, $profiles);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe(['public.orders.payload'])
        ->and($tree->branches[2]->fix)->toBeNull();
});

it('ignores a column that is neither json nor jsonb entirely', function (): void {
    $columns = [jsonColumnsColumn('orders', 'notes', 'text')];
    $profiles = [jsonColumnsProfile(['name' => 'orders', 'liveTuples' => 50_000])];

    $tree = jsonColumnsLesson()->fork($columns, $profiles);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('treats a jsonb column with no matching profile as belonging to a small table', function (): void {
    $columns = [jsonColumnsColumn('ghost', 'payload', 'jsonb')];

    $tree = jsonColumnsLesson()->fork($columns, []);

    expect($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe(['public.ghost.payload']);
});

it('renders all three branches when nothing landed on any of them', function (): void {
    $tree = jsonColumnsLesson()->fork([], []);

    expect($tree->branches)->toHaveCount(3)
        ->and($tree->branches[0]->condition)->not->toBeEmpty()
        ->and($tree->branches[1]->condition)->not->toBeEmpty()
        ->and($tree->branches[2]->condition)->not->toBeEmpty();
});
