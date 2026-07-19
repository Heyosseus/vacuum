<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Lessons\DeadTuples;
use Heyosseus\Vacuum\Learn\Lessons\Fillfactor;
use Heyosseus\Vacuum\Learn\Lessons\RowVersions;
use Heyosseus\Vacuum\Learn\Lessons\UnusedIndexes;
use Illuminate\Support\Facades\DB;

/**
 * What each lesson says when there is nothing to say.
 *
 * A lesson never renders an empty table: it explains why it found nothing, and
 * those explanations are the half of each class the live test database can never
 * exercise on its own, because it always has tables and they always have rows.
 * The ignored-schema list is the honest seam for that -- Vacuum already reports
 * on nothing in a schema an application has asked it to leave alone, so hiding
 * every schema is the supported way to ask a lesson what it does with an empty
 * database rather than a mock standing in for one.
 */
function hideEverySchema(): void
{
    /** @var list<object{nspname: string}> $schemas */
    $schemas = DB::select('SELECT nspname FROM pg_namespace');

    config()->set('vacuum.ignored_schemas', array_map(
        static fn (object $schema): string => $schema->nspname,
        $schemas,
    ));
}

it('says a database with nothing in it has nothing to show', function (): void {
    hideEverySchema();

    $rowVersions = app(RowVersions::class)->observe();

    expect($rowVersions->headline)->toBe('This database has no tables yet.')
        ->and($rowVersions->isEmpty())->toBeTrue()
        ->and($rowVersions->note)->not->toBeNull()
        // Nothing to hand the reader either: the statement names a table, and there
        // is no table to name.
        ->and(app(RowVersions::class)->tryIt())->toBeNull();

    $deadTuples = app(DeadTuples::class)->observe();

    expect($deadTuples->headline)->toBe('This database has no tables yet.')
        ->and($deadTuples->isEmpty())->toBeTrue();

    $fillfactor = app(Fillfactor::class)->observe();

    expect($fillfactor->isEmpty())->toBeTrue()
        ->and($fillfactor->note)->toContain('needs writes to exist');

    $indexes = app(UnusedIndexes::class)->observe();

    expect($indexes->isEmpty())->toBeTrue()
        ->and($indexes->note)->toContain('nothing to flag');
});

/**
 * A table that exists and holds no rows is a different answer from no table at
 * all, and the row versions lesson is the only one that can tell them apart.
 */
it('says so when the largest table is empty', function (): void {
    DB::statement('DROP SCHEMA IF EXISTS learn_probe CASCADE');
    DB::statement('CREATE SCHEMA learn_probe');
    DB::statement('CREATE TABLE learn_probe.untouched (id int)');
    flushStatistics();

    try {
        // Every schema but the one just made, so the probe table is the largest
        // table this connection has.
        /** @var list<object{nspname: string}> $schemas */
        $schemas = DB::select("SELECT nspname FROM pg_namespace WHERE nspname <> 'learn_probe'");

        config()->set('vacuum.ignored_schemas', array_map(
            static fn (object $schema): string => $schema->nspname,
            $schemas,
        ));

        $observation = app(RowVersions::class)->observe();

        expect($observation->headline)->toContain('learn_probe.untouched')
            ->and($observation->headline)->toContain('it is empty')
            ->and($observation->isEmpty())->toBeTrue()
            ->and(app(RowVersions::class)->tryIt())->toContain('learn_probe');
    } finally {
        DB::statement('DROP SCHEMA IF EXISTS learn_probe CASCADE');
    }
});

/**
 * The other half of the index lesson: a real index nothing has ever read and
 * which enforces nothing, which is the only kind it is ever right to name.
 */
it('names an index that is read by nothing and constrains nothing', function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_idle');
    DB::statement('CREATE TABLE learn_idle (id serial PRIMARY KEY, label text)');
    DB::statement('CREATE INDEX learn_idle_label_idx ON learn_idle (label)');
    flushStatistics();

    try {
        $observation = app(UnusedIndexes::class)->observe();

        expect($observation->isEmpty())->toBeFalse()
            ->and($observation->headline)->toContain('never been read')
            ->and($observation->columns)->toBe(['index', 'table', 'size', 'scans']);

        $named = implode(' ', array_map(static fn (array $row): string => $row[0], $observation->rows));

        expect($named)->toContain('learn_idle_label_idx');
    } finally {
        DB::statement('DROP TABLE IF EXISTS learn_idle');
    }
});
