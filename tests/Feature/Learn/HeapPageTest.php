<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Internals\Explorers\HeapPages;
use Heyosseus\Vacuum\Learn\Lessons\HeapPage;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;

/**
 * A table with real rows, so observe() has an actual page count to report,
 * and an isolated schema for the zero-page/zero-row guard, which no live
 * table can demonstrate honestly without being created empty on purpose.
 */
beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_heap_crates');
    DB::statement('CREATE TABLE learn_heap_crates (id serial PRIMARY KEY, label text)');
    DB::insert("INSERT INTO learn_heap_crates (label) SELECT 'crate ' || i FROM generate_series(1, 500) i");
    flushStatistics();
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_heap_crates');
    DB::statement('DROP SCHEMA IF EXISTS learn_heap_empty CASCADE');
});

/**
 * Named heapPageLesson() rather than anything shorter: profile(), forkProfile(),
 * hideEverySchema(), indexStatistic(), constraintOn() and columnsOf() are all
 * already declared as global helpers elsewhere in this suite, and a second
 * declaration of any of them is a fatal redeclaration error the moment both
 * files load in the same full-suite run.
 */
function heapPageLesson(): HeapPage
{
    return new HeapPage(
        app(TableProfiles::class),
        app(Capabilities::class),
        app(HeapPages::class),
        app(Repository::class),
    );
}

it('names its slug, title, tier, hook and prerequisite', function (): void {
    $lesson = heapPageLesson();

    expect($lesson->slug())->toBe('heap-page')
        ->and($lesson->title())->toBe('Inside a heap page')
        ->and($lesson->tier())->toBe(Heyosseus\Vacuum\Learn\Tier::Advanced)
        ->and($lesson->after())->toBe('row-versions')
        ->and($lesson->hook())->not->toBeEmpty();
});

it('names the reader own largest table and its page count', function (): void {
    $observation = heapPageLesson()->observe();

    expect($observation->isEmpty())->toBeFalse()
        ->and($observation->columns)->toBe(['table', 'heap size', 'pages', 'live rows', 'rows per page'])
        ->and($observation->rows)->not->toBeEmpty();

    $tables = implode(' ', array_map(static fn (array $row): string => $row[0], $observation->rows));

    // learn_heap_crates has 500 rows and no other table on this shared connection
    // is guaranteed to be smaller, so this only proves the table is named among the
    // ten largest -- not that it is necessarily first.
    expect($tables)->toContain('learn_heap_crates')
        ->and($observation->headline)->toContain('page(s)')
        ->and($observation->headline)->toContain('row(s) per page');
});

it('says a database with nothing in it has nothing to show', function (): void {
    /** @var list<object{nspname: string}> $schemas */
    $schemas = DB::select('SELECT nspname FROM pg_namespace');

    config()->set('vacuum.ignored_schemas', array_map(
        static fn (object $schema): string => $schema->nspname,
        $schemas,
    ));

    $observation = heapPageLesson()->observe();

    expect($observation->headline)->toBe('This database has no tables yet.')
        ->and($observation->isEmpty())->toBeTrue()
        ->and($observation->note)->not->toBeNull();
});

/**
 * A table created and never written to has zero heap bytes -- pg_relation_size
 * never allocates a first page until something is inserted -- so this is the one
 * honest way to reach the pages()/rowsPerPage() zero guards through observe()
 * itself, rather than assert on a private method directly.
 */
it('guards against dividing by zero when the largest table has no pages and no rows', function (): void {
    DB::statement('DROP SCHEMA IF EXISTS learn_heap_empty CASCADE');
    DB::statement('CREATE SCHEMA learn_heap_empty');
    DB::statement('CREATE TABLE learn_heap_empty.untouched (id int)');
    flushStatistics();

    /** @var list<object{nspname: string}> $schemas */
    $schemas = DB::select("SELECT nspname FROM pg_namespace WHERE nspname <> 'learn_heap_empty'");

    config()->set('vacuum.ignored_schemas', array_map(
        static fn (object $schema): string => $schema->nspname,
        $schemas,
    ));

    $observation = heapPageLesson()->observe();

    expect($observation->isEmpty())->toBeFalse()
        ->and($observation->headline)->toContain('learn_heap_empty.untouched')
        ->and($observation->headline)->toContain('0 page(s)')
        ->and($observation->headline)->toContain('0.0 row(s) per page');

    $row = $observation->rows[0];

    expect($row[0])->toBe('learn_heap_empty.untouched')
        ->and($row[2])->toBe('0')
        ->and($row[3])->toBe('0')
        ->and($row[4])->toBe('0.0');
});

it('hands the reader a runnable statement for band three', function (): void {
    $sql = heapPageLesson()->tryIt();

    expect($sql)->toBeString()
        ->and($sql)->toContain('pg_class')
        ->and($sql)->toContain('block_size');
});

it('delegates tree() to fork() using its own live capabilities and config', function (): void {
    $tree = heapPageLesson()->tree();

    expect($tree->question)->not->toBeEmpty()
        ->and($tree->branches)->toHaveCount(3);
});

it('lands on the open branch when pageinspect is installed and internals are enabled and reachable', function (): void {
    $tree = heapPageLesson()->fork(true, true, true);

    expect($tree->branches[0]->landed)->toBe(['this connection'])
        ->and($tree->branches[0]->fix)->toBeNull()
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('lands on the switched-off branch when pageinspect is installed but internals are disabled', function (): void {
    $tree = heapPageLesson()->fork(true, false, false);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe(['this connection'])
        ->and($tree->branches[1]->fix)->toBe('VACUUM_INTERNALS_ENABLED=true')
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('lands on the cannot-open branch when pageinspect is missing entirely', function (): void {
    $tree = heapPageLesson()->fork(false, false, false);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe(['this connection'])
        ->and($tree->branches[2]->fix)->toContain('create extension pageinspect;');
});

it('lands on the cannot-open branch when pageinspect is installed and enabled but the role is not superuser', function (): void {
    $tree = heapPageLesson()->fork(true, true, false);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe(['this connection']);
});
