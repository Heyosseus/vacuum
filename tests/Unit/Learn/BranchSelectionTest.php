<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Learn\Lessons\DeadTuples;
use Heyosseus\Vacuum\Learn\Lessons\Fillfactor;
use Heyosseus\Vacuum\Learn\Lessons\UnusedIndexes;
use Heyosseus\Vacuum\Values\IndexStatistic;
use Heyosseus\Vacuum\Values\TableProfile;

/**
 * Builds a table profile with every field zeroed out except the ones this
 * suite is actually exercising, so a helper call reads as "here is what
 * matters" rather than a twenty-argument wall.
 */
function forkProfile(
    string $name,
    int $updates = 0,
    int $hotUpdates = 0,
    ?int $fillfactor = null,
    int $liveTuples = 0,
    int $deadTuples = 0,
    bool $vacuumed = false,
): TableProfile {
    return new TableProfile(
        schema: 'public',
        name: $name,
        liveTuples: $liveTuples,
        deadTuples: $deadTuples,
        modificationsSinceAnalyze: 0,
        xidAge: 0,
        mxidAge: 0,
        heapBytes: 0,
        indexBytes: 0,
        toastBytes: 0,
        totalBytes: 0,
        sequentialScans: 0,
        sequentialTuplesRead: 0,
        indexScans: 0,
        indexTuplesFetched: 0,
        inserts: 0,
        updates: $updates,
        hotUpdates: $hotUpdates,
        deletes: 0,
        lastVacuum: null,
        lastAutovacuum: $vacuumed ? CarbonImmutable::now() : null,
        lastAnalyze: null,
        lastAutoanalyze: null,
        vacuumScaleFactor: 0.2,
        vacuumThreshold: 0,
        analyzeScaleFactor: 0.1,
        analyzeThreshold: 0,
        tuned: false,
        fillfactor: $fillfactor,
    );
}

function indexStatistic(string $table, string $name, int $scans, bool $unique = false, bool $primary = false): IndexStatistic
{
    return new IndexStatistic(
        schema: 'public',
        table: $table,
        name: $name,
        scans: $scans,
        bytes: 0,
        unique: $unique,
        primary: $primary,
        valid: true,
        countingSince: null,
    );
}

/**
 * Two tables with the same poor HOT share and different causes. The ratio alone
 * cannot tell them apart, which is exactly why the branch has to -- and why this
 * builds the profiles rather than reading whatever the test database happens to
 * be carrying.
 */
it('sends a default-fillfactor table and an already-lowered one to different fixes', function (): void {
    $tree = app(Fillfactor::class)->fork([
        forkProfile('sessions', updates: 1000, hotUpdates: 100),
        forkProfile('orders', updates: 1000, hotUpdates: 100, fillfactor: 85),
    ]);

    expect($tree->branches[0]->landed)->toBe(['public.sessions'])
        ->and($tree->branches[1]->landed)->toBe(['public.orders'])
        ->and($tree->branches[0]->fix)->toContain('fillfactor = 85');
});

it('leaves a healthy table off the tree entirely', function (): void {
    $tree = app(Fillfactor::class)->fork([
        forkProfile('logs', updates: 1000, hotUpdates: 990),
    ]);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[0]->fix)->toBeNull();
});

it('renders both fillfactor branches when nothing landed on either', function (): void {
    $tree = app(Fillfactor::class)->fork([]);

    expect($tree->branches)->toHaveCount(2)
        ->and($tree->branches[0]->condition)->not->toBeEmpty()
        ->and($tree->branches[1]->condition)->not->toBeEmpty();
});

it('ignores a table that has never been updated', function (): void {
    // A table with no updates has no HOT share to be poor. Landing it on the
    // "still at the default fillfactor" branch would advise an ALTER that
    // cannot help, on a table that has no problem.
    $tree = app(Fillfactor::class)->fork([
        forkProfile('audit_log', updates: 0, hotUpdates: 0),
    ]);

    expect($tree->branches[0]->landed)->toBe([]);
});

it('sends a never-vacuumed table and a stuck one to different dead-tuple fixes', function (): void {
    $tree = app(DeadTuples::class)->fork([
        forkProfile('events', liveTuples: 1000, deadTuples: 500, vacuumed: false),
        forkProfile('accounts', liveTuples: 1000, deadTuples: 500, vacuumed: true),
    ]);

    expect($tree->branches[0]->landed)->toBe(['public.events'])
        ->and($tree->branches[1]->landed)->toBe(['public.accounts'])
        ->and($tree->branches[0]->fix)->toContain('autovacuum_vacuum_scale_factor = 0.05');
});

it('leaves a table with a low dead share off the dead-tuples tree', function (): void {
    $tree = app(DeadTuples::class)->fork([
        forkProfile('settings', liveTuples: 1000, deadTuples: 1),
    ]);

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([]);
});

it('renders both dead-tuple branches when nothing landed on either', function (): void {
    $tree = app(DeadTuples::class)->fork([]);

    expect($tree->branches)->toHaveCount(2)
        ->and($tree->branches[0]->condition)->not->toBeEmpty()
        ->and($tree->branches[1]->condition)->not->toBeEmpty();
});

it('sends a constraining unused index and a purely unread one to different fixes', function (): void {
    $tree = app(UnusedIndexes::class)->fork([
        indexStatistic('accounts', 'accounts_email_unique', scans: 0, unique: true),
        indexStatistic('accounts', 'accounts_created_at_index', scans: 0),
    ]);

    expect($tree->branches[0]->landed)->toBe(['public.accounts_email_unique'])
        ->and($tree->branches[1]->landed)->toBe(['public.accounts_created_at_index'])
        ->and($tree->branches[1]->fix)->toContain('drop index concurrently');
});

it('renders both unused-index branches when nothing landed on either', function (): void {
    $tree = app(UnusedIndexes::class)->fork([]);

    expect($tree->branches)->toHaveCount(2)
        ->and($tree->branches[0]->condition)->not->toBeEmpty()
        ->and($tree->branches[1]->condition)->not->toBeEmpty();
});
