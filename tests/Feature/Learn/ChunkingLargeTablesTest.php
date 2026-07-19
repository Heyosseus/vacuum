<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Lessons\ChunkingLargeTables;
use Heyosseus\Vacuum\Queries\Constraints;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Values\Constraint;
use Heyosseus\Vacuum\Values\TableProfile;
use Illuminate\Support\Facades\DB;

/**
 * A real table with a primary key, so observe() can be proven to name it and
 * report its key on a live database rather than only on values built by hand.
 */
beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_chunk_orders');

    DB::statement('CREATE TABLE learn_chunk_orders (id serial PRIMARY KEY, label text)');
    DB::insert("INSERT INTO learn_chunk_orders (label) SELECT 'x' || i FROM generate_series(1, 50) i");

    flushStatistics();
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_chunk_orders');
});

function chunkingLesson(): ChunkingLargeTables
{
    return new ChunkingLargeTables(app(TableProfiles::class), app(Constraints::class));
}

/**
 * A TableProfile with every one of its ~25 required parameters zeroed out except
 * whatever a test actually cares about. TableProfile is final readonly and cannot
 * be mocked, so fork() can only be exercised against values built this way. Named
 * chunkingProfile() rather than profile(), forkProfile() or tableProfileWithRows(),
 * all of which are already declared by other test files loaded in the same suite
 * run.
 *
 * @param  array<string, mixed>  $overrides
 */
function chunkingProfile(array $overrides = []): TableProfile
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

/**
 * @param  array<string, mixed>  $overrides
 */
function chunkingConstraint(array $overrides = []): Constraint
{
    $defaults = [
        'schema' => 'public',
        'table' => 'orders',
        'name' => 'orders_pkey',
        'kind' => 'p',
        'columns' => ['id'],
        'referencedTable' => '',
        'indexed' => true,
    ];

    return new Constraint(...array_merge($defaults, $overrides));
}

it('names the real table and reports its primary key', function (): void {
    $observation = chunkingLesson()->observe();

    expect($observation->isEmpty())->toBeFalse()
        ->and($observation->columns)->toBe(['table', 'rows', 'primary key', 'sequential scans']);

    $matching = array_values(array_filter(
        $observation->rows,
        static fn (array $row): bool => $row[0] === 'public.learn_chunk_orders',
    ));

    expect($matching)->toHaveCount(1)
        ->and($matching[0][2])->toBe('id');
});

/**
 * The ignored-schema list is the supported way to ask a lesson what it does
 * with an empty database rather than a mock standing in for one -- Vacuum
 * already reports on nothing in a schema an application asked it to leave
 * alone, so hiding every schema leaves TableProfiles::all() with nothing to
 * return. Inlined rather than pulled into a named helper, because
 * hideEverySchema() is already declared by LessonsEmptyTest.php.
 */
it('says so when there are no tables', function (): void {
    /** @var list<object{nspname: string}> $schemas */
    $schemas = DB::select('SELECT nspname FROM pg_namespace');

    config()->set('vacuum.ignored_schemas', array_map(
        static fn (object $schema): string => $schema->nspname,
        $schemas,
    ));

    $observation = chunkingLesson()->observe();

    expect($observation->headline)->toBe('This database has no tables to measure yet.')
        ->and($observation->isEmpty())->toBeTrue()
        ->and($observation->note)->not->toBeNull()
        ->and(chunkingLesson()->tryIt())->toBeNull();
});

it('hands the reader a runnable EXPLAIN for band three, naming a real table', function (): void {
    $sql = chunkingLesson()->tryIt();

    expect($sql)->toBeString()
        ->and($sql)->toContain('explain analyze')
        ->and($sql)->toContain('offset 100000')
        ->and($sql)->toContain('learn_chunk_orders');
});

it('describes itself for the curriculum', function (): void {
    $lesson = chunkingLesson();

    expect($lesson->slug())->toBe('chunking-large-tables')
        ->and($lesson->title())->toBe('Why chunk() gets slower as it goes')
        ->and($lesson->tier())->toBe(Heyosseus\Vacuum\Learn\Tier::Eloquent)
        ->and($lesson->after())->toBeNull()
        ->and($lesson->hook())->not->toBeEmpty();
});

it('delegates tree() to fork() using its own live data', function (): void {
    $tree = chunkingLesson()->tree();

    expect($tree->question)->toBe('Which iteration method should this table use?')
        ->and($tree->branches)->toHaveCount(3);
});

/**
 * Three tables -- large with a single-column primary key, large with no usable
 * key, and small -- built rather than measured, since a live database offers no
 * guarantee it demonstrates all three branches at once.
 */
it('sends a large table with a single-column primary key to the chunkById branch', function (): void {
    $large = chunkingProfile(['name' => 'orders', 'liveTuples' => 500_000]);
    $key = chunkingConstraint(['table' => 'orders', 'columns' => ['id']]);

    $tree = chunkingLesson()->fork([$large], [$key]);

    [$keyed, $unkeyed, $small] = $tree->branches;

    expect($keyed->isTaken())->toBeTrue()
        ->and($keyed->landed[0])->toBe('public.orders')
        ->and($keyed->fix)->toContain('chunkById(1000')
        ->and($keyed->fix)->toContain("column: 'id'")
        ->and($unkeyed->isTaken())->toBeFalse()
        ->and($small->isTaken())->toBeFalse();
});

it('sends a large table with no usable single-column primary key to the awkward branch', function (): void {
    $large = chunkingProfile(['name' => 'events', 'liveTuples' => 500_000]);

    // A composite primary key: two columns, so chunkById() has no single value to
    // order and compare on, and this table is sent down the same branch as a
    // table with no primary key at all.
    $composite = chunkingConstraint(['table' => 'events', 'columns' => ['tenant_id', 'id']]);

    $tree = chunkingLesson()->fork([$large], [$composite]);

    [$keyed, $unkeyed, $small] = $tree->branches;

    expect($keyed->isTaken())->toBeFalse()
        ->and($unkeyed->isTaken())->toBeTrue()
        ->and($unkeyed->landed[0])->toBe('public.events')
        ->and($unkeyed->fix)->toBeNull();
});

it('sends a large table with no primary key at all to the awkward branch', function (): void {
    $large = chunkingProfile(['name' => 'logs', 'liveTuples' => 500_000]);

    $tree = chunkingLesson()->fork([$large], []);

    [$keyed, $unkeyed, $small] = $tree->branches;

    expect($keyed->isTaken())->toBeFalse()
        ->and($unkeyed->isTaken())->toBeTrue()
        ->and($unkeyed->landed[0])->toBe('public.logs');
});

it('sends a small table to the "it does not matter" branch regardless of key', function (): void {
    $small = chunkingProfile(['name' => 'tags', 'liveTuples' => 12]);
    $key = chunkingConstraint(['table' => 'tags', 'columns' => ['id']]);

    $tree = chunkingLesson()->fork([$small], [$key]);

    [$keyed, $unkeyed, $smallBranch] = $tree->branches;

    expect($keyed->isTaken())->toBeFalse()
        ->and($unkeyed->isTaken())->toBeFalse()
        ->and($smallBranch->isTaken())->toBeTrue()
        ->and($smallBranch->landed[0])->toBe('public.tags');
});

it('renders all three branches when nothing landed on any of them', function (): void {
    $tree = chunkingLesson()->fork([], []);

    expect($tree->branches)->toHaveCount(3)
        ->and($tree->branches[0]->condition)->not->toBeEmpty()
        ->and($tree->branches[1]->condition)->not->toBeEmpty()
        ->and($tree->branches[2]->condition)->not->toBeEmpty();
});
