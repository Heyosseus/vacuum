<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Values\TableProfile;

function profiled(
    int $live = 1_000,
    int $dead = 0,
    int $sequentialScans = 0,
    int $indexScans = 0,
    int $updates = 0,
    int $hotUpdates = 0,
    float $vacuumScaleFactor = 0.2,
    int $vacuumThreshold = 50,
    ?CarbonImmutable $vacuum = null,
    ?CarbonImmutable $autovacuum = null,
): TableProfile {
    return new TableProfile(
        schema: 'public',
        name: 'crates',
        liveTuples: $live,
        deadTuples: $dead,
        modificationsSinceAnalyze: 0,
        xidAge: 0,
        heapBytes: 0,
        indexBytes: 0,
        toastBytes: 0,
        totalBytes: 0,
        sequentialScans: $sequentialScans,
        sequentialTuplesRead: 0,
        indexScans: $indexScans,
        indexTuplesFetched: 0,
        inserts: 0,
        updates: $updates,
        hotUpdates: $hotUpdates,
        deletes: 0,
        lastVacuum: $vacuum,
        lastAutovacuum: $autovacuum,
        lastAnalyze: null,
        lastAutoanalyze: null,
        vacuumScaleFactor: $vacuumScaleFactor,
        vacuumThreshold: $vacuumThreshold,
        analyzeScaleFactor: 0.1,
        analyzeThreshold: 50,
        tuned: false,
    );
}

it('qualifies its name with its schema', function (): void {
    expect(profiled()->qualifiedName())->toBe('public.crates');
});

it('measures the share of reads that scanned the whole table', function (): void {
    expect(profiled(sequentialScans: 30, indexScans: 70)->sequentialShare())->toBe(0.3);
});

it('will not put a share on a table nothing has read', function (): void {
    // Zero would read as "no scans", which is a different fact from "no reads", and
    // the page should be able to say the different thing.
    expect(profiled(sequentialScans: 0, indexScans: 0)->sequentialShare())->toBeNull();
});

it('measures the share of updates that stayed inside their page', function (): void {
    expect(profiled(updates: 200, hotUpdates: 50)->hotUpdateRatio())->toBe(0.25);
});

it('will not put a share on a table nothing has updated', function (): void {
    expect(profiled(updates: 0)->hotUpdateRatio())->toBeNull();
});

it('works out the dead rows autovacuum is actually waiting for', function (): void {
    // The number nobody can read off the setting, because the setting is a scale
    // factor: a table of fifty million rows is allowed ten million dead ones.
    expect(profiled(live: 50_000_000)->vacuumsAt())->toBe(50 + 10_000_000)
        ->and(profiled(live: 50_000_000, vacuumScaleFactor: 0.01)->vacuumsAt())->toBe(50 + 500_000)
        ->and(profiled(live: 1_000)->analyzesAt())->toBe(50 + 100);
});

it('measures dead tuples as a share of all tuples', function (): void {
    expect(profiled(live: 750, dead: 250)->deadTupleRatio())->toBe(0.25)
        ->and(profiled(live: 0, dead: 0)->deadTupleRatio())->toBe(0.0);
});

it('takes the most recent of a manual and an automatic vacuum', function (): void {
    $older = CarbonImmutable::parse('2026-07-01 10:00:00');
    $newer = CarbonImmutable::parse('2026-07-09 10:00:00');

    expect(profiled(vacuum: $older, autovacuum: $newer)->lastVacuumedAt())->toEqual($newer)
        ->and(profiled(vacuum: $newer, autovacuum: $older)->lastVacuumedAt())->toEqual($newer)
        ->and(profiled(vacuum: $older)->lastVacuumedAt())->toEqual($older)
        ->and(profiled()->lastVacuumedAt())->toBeNull()
        ->and(profiled()->lastAnalyzedAt())->toBeNull();
});
