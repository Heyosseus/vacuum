<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Curriculum;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Learn\Lessons\DeadTuples;
use Heyosseus\Vacuum\Learn\Lessons\Fillfactor;
use Heyosseus\Vacuum\Learn\Lessons\RowVersions;
use Heyosseus\Vacuum\Learn\Lessons\UnusedIndexes;
use Illuminate\Support\Facades\DB;

/*
 * A table with rows, an update and a delete behind it, so every lesson has
 * something of its own to talk about: row versions to list, dead tuples to
 * count, and at least one update to measure a HOT ratio from. The suite runs
 * against a live, shared database whose other statistics move underneath it,
 * so nothing here asserts an exact number -- only that each lesson names its
 * own data rather than staying silent about it.
 */
beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS lesson_crates');
    DB::statement('CREATE TABLE lesson_crates (id serial PRIMARY KEY, label text)');
    DB::insert("INSERT INTO lesson_crates (label) SELECT 'crate ' || i FROM generate_series(1, 50) i");
    DB::update("UPDATE lesson_crates SET label = label || '!'");
    DB::delete('DELETE FROM lesson_crates WHERE id <= 5');
    flushStatistics();
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS lesson_crates');
});

it('registers every lesson under a unique slug', function (): void {
    $slugs = array_map(static fn (Lesson $l): string => $l->slug(), app(Curriculum::class)->all());

    // Uniqueness is the invariant worth guarding: two lessons sharing a slug
    // means one of them is unreachable, and the router would resolve whichever
    // was tagged first. The curriculum's size is not an invariant -- it grows
    // every time someone writes a lesson -- so asserting a count here would be
    // a maintenance tax that catches nothing.
    expect($slugs)->toHaveCount(count(array_unique($slugs)))
        ->and($slugs)->toContain('row-versions', 'dead-tuples', 'fillfactor', 'unused-indexes');
});

it('names the reader own tables in the row-versions lesson', function (): void {
    $observation = app(RowVersions::class)->observe();

    expect($observation->headline)->not->toBeEmpty();

    // Either it lists real row versions, or it says why not -- never a blank table.
    if ($observation->isEmpty()) {
        expect($observation->note)->not->toBeNull();
    } else {
        expect($observation->columns)->toBe(['ctid', 'xmin', 'xmax'])
            ->and($observation->rows)->not->toBeEmpty();
    }
});

it('counts dead tuples across the reader own tables', function (): void {
    $observation = app(DeadTuples::class)->observe();

    expect($observation->headline)->not->toBeEmpty()
        ->and($observation->columns)
        ->toBe(['table', 'live rows', 'dead rows', 'dead share', 'vacuums at', 'last vacuumed'])
        ->and($observation->rows)->not->toBeEmpty();
});

it('finds the reader own worst HOT-update ratio, or says why it cannot yet', function (): void {
    $observation = app(Fillfactor::class)->observe();

    expect($observation->headline)->not->toBeEmpty();

    if ($observation->isEmpty()) {
        expect($observation->note)->not->toBeNull();
    } else {
        expect($observation->columns)->toBe(['table', 'updates', 'HOT updates', 'HOT share', 'fillfactor'])
            ->and($observation->rows)->not->toBeEmpty();
    }
});

it('names how many of the reader own indexes have never been read', function (): void {
    $observation = app(UnusedIndexes::class)->observe();

    expect($observation->headline)->not->toBeEmpty();

    if ($observation->isEmpty()) {
        expect($observation->note)->not->toBeNull();
    } else {
        expect($observation->columns)->toBe(['index', 'table', 'size', 'scans'])
            ->and($observation->rows)->not->toBeEmpty();
    }
});

it('hands the reader a runnable statement for band three', function (): void {
    expect(app(RowVersions::class)->tryIt())->toBeString()
        ->and(app(DeadTuples::class)->tryIt())->toBeString()
        ->and(app(Fillfactor::class)->tryIt())->toBeString()
        ->and(app(UnusedIndexes::class)->tryIt())->toBeString();
});
