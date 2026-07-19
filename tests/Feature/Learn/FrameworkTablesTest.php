<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Lessons\FrameworkTables;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Values\TableProfile;
use Illuminate\Support\Facades\DB;

/*
 * The names this lesson recognises. Dropped defensively before and after every
 * test, since the test database is shared and nothing else in the suite is
 * meant to leave one of these six names behind -- but a lesson that names
 * "sessions" by convention deserves a test that does not silently pass
 * because somebody else's leftover table happened to already be there.
 */
const FRAMEWORK_TABLE_NAMES = ['sessions', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks'];

beforeEach(function (): void {
    foreach (FRAMEWORK_TABLE_NAMES as $name) {
        DB::statement("DROP TABLE IF EXISTS {$name}");
    }
});

afterEach(function (): void {
    foreach (FRAMEWORK_TABLE_NAMES as $name) {
        DB::statement("DROP TABLE IF EXISTS {$name}");
    }
});

/**
 * A TableProfile with every one of its ~25 required parameters zeroed out
 * except whatever the test actually cares about. TableProfile is final
 * readonly and cannot be mocked, so fork() can only be exercised against
 * values built this way. Named frameworkTableProfile() rather than profile()
 * because tests/Feature/Queries/TableProfilesTest.php already declares a
 * global profile() helper, and a second one under that name is a fatal
 * redeclaration error the moment both files load in the same suite run.
 *
 * @param  array<string, mixed>  $overrides
 */
function frameworkTableProfile(array $overrides = []): TableProfile
{
    $defaults = [
        'schema' => 'public',
        'name' => 'sessions',
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

it('names a real sessions table when the database session driver is in use', function (): void {
    DB::statement('CREATE TABLE sessions (id varchar PRIMARY KEY, payload text, last_activity integer)');
    DB::insert("INSERT INTO sessions (id, payload, last_activity) SELECT 's' || i, 'x', i FROM generate_series(1, 20) i");
    flushStatistics();

    $observation = (new FrameworkTables(app(TableProfiles::class)))->observe();

    expect($observation->headline)->toContain('sessions')
        ->and($observation->columns)->toBe(['table', 'live rows', 'dead rows', 'dead share', 'HOT share', 'fillfactor'])
        ->and($observation->rows)->not->toBeEmpty();
});

it('says why there is nothing to show when no framework table exists', function (): void {
    $observation = (new FrameworkTables(app(TableProfiles::class)))->observe();

    expect($observation->isEmpty())->toBeTrue()
        ->and($observation->headline)->not->toBeEmpty()
        ->and($observation->note)->not->toBeNull();
});

it('hands the reader a runnable statement for band three', function (): void {
    $sql = (new FrameworkTables(app(TableProfiles::class)))->tryIt();

    expect($sql)->toContain('pg_stat_user_tables')
        ->and($sql)->toContain('sessions');
});

it('sends a framework table at the default fillfactor to the fillfactor branch', function (): void {
    // High dead share, fillfactor never set -- the "leave it room" fix.
    $sessions = frameworkTableProfile([
        'name' => 'sessions',
        'liveTuples' => 100,
        'deadTuples' => 50,
        'fillfactor' => null,
        'lastAutovacuum' => Carbon\CarbonImmutable::now(),
    ]);

    $tree = (new FrameworkTables(app(TableProfiles::class)))->fork([$sessions]);

    expect($tree->branches[0]->landed)->toBe(['public.sessions'])
        ->and($tree->branches[0]->fix)->toBe('alter table public.sessions set (fillfactor = 85);')
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('sends a never-vacuumed framework table to the autovacuum branch', function (): void {
    // High dead share, fillfactor already lowered, but autovacuum has never run.
    $jobs = frameworkTableProfile([
        'name' => 'jobs',
        'liveTuples' => 100,
        'deadTuples' => 50,
        'fillfactor' => 85,
        'lastAutovacuum' => null,
    ]);

    $tree = (new FrameworkTables(app(TableProfiles::class)))->fork([$jobs]);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe(['public.jobs'])
        ->and($tree->branches[1]->fix)->toBe('alter table public.jobs set (autovacuum_vacuum_scale_factor = 0.05);')
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('sends a healthy framework table to the all-clear branch', function (): void {
    $cache = frameworkTableProfile([
        'name' => 'cache',
        'liveTuples' => 100,
        'deadTuples' => 5,
        'fillfactor' => null,
        'lastAutovacuum' => Carbon\CarbonImmutable::now(),
    ]);

    $tree = (new FrameworkTables(app(TableProfiles::class)))->fork([$cache]);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe(['public.cache'])
        ->and($tree->branches[2]->fix)->toBeNull();
});

it('ignores a table that is not one of the six framework names', function (): void {
    $orders = frameworkTableProfile([
        'name' => 'orders',
        'liveTuples' => 100,
        'deadTuples' => 90,
        'fillfactor' => null,
        'lastAutovacuum' => null,
    ]);

    $tree = (new FrameworkTables(app(TableProfiles::class)))->fork([$orders]);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('describes itself for the curriculum', function (): void {
    $lesson = new FrameworkTables(app(TableProfiles::class));

    expect($lesson->slug())->toBe('framework-tables')
        ->and($lesson->title())->toBe('The tables Laravel writes for you')
        ->and($lesson->tier())->toBe(Heyosseus\Vacuum\Learn\Tier::Eloquent)
        ->and($lesson->after())->toBeNull()
        ->and($lesson->hook())->not->toBeEmpty()
        ->and($lesson->tree())->toBeInstanceOf(Heyosseus\Vacuum\Learn\Tree::class);
});
