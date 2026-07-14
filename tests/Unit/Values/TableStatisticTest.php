<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Values\TableStatistic;

function statistic(int $live = 0, int $dead = 0, ?CarbonImmutable $vacuum = null, ?CarbonImmutable $autovacuum = null): TableStatistic
{
    return new TableStatistic(
        schema: 'public',
        name: 'widgets',
        liveTuples: $live,
        deadTuples: $dead,
        modificationsSinceAnalyze: 0,
        lastVacuum: $vacuum,
        lastAutovacuum: $autovacuum,
        lastAnalyze: null,
        lastAutoanalyze: null,
    );
}

it('qualifies its name with its schema', function (): void {
    expect(statistic()->qualifiedName())->toBe('public.widgets');
});

it('measures dead tuples as a share of all tuples', function (): void {
    expect(statistic(live: 750, dead: 250)->deadTupleRatio())->toBe(0.25);
});

it('reports no bloat for a table that has never held a row', function (): void {
    expect(statistic(live: 0, dead: 0)->deadTupleRatio())->toBe(0.0);
});

it('treats a table of nothing but dead tuples as entirely bloated', function (): void {
    expect(statistic(live: 0, dead: 40)->deadTupleRatio())->toBe(1.0);
});

it('takes the most recent of a manual and an automatic vacuum', function (): void {
    $manual = CarbonImmutable::parse('2026-07-01 10:00:00');
    $automatic = CarbonImmutable::parse('2026-07-09 10:00:00');

    expect(statistic(vacuum: $manual, autovacuum: $automatic)->lastVacuumedAt())->toEqual($automatic)
        ->and(statistic(vacuum: $automatic, autovacuum: $manual)->lastVacuumedAt())->toEqual($automatic);
});

it('has never been vacuumed when neither kind of vacuum has run', function (): void {
    expect(statistic()->lastVacuumedAt())->toBeNull();
});

it('takes the most recent of a manual and an automatic analyze', function (): void {
    $manual = CarbonImmutable::parse('2026-07-01 10:00:00');
    $automatic = CarbonImmutable::parse('2026-07-09 10:00:00');

    $table = new TableStatistic(
        schema: 'public',
        name: 'widgets',
        liveTuples: 0,
        deadTuples: 0,
        modificationsSinceAnalyze: 0,
        lastVacuum: null,
        lastAutovacuum: null,
        lastAnalyze: $manual,
        lastAutoanalyze: $automatic,
    );

    expect($table->lastAnalyzedAt())->toEqual($automatic);
});

it('has never been analyzed when neither kind of analyze has run', function (): void {
    expect(statistic()->lastAnalyzedAt())->toBeNull();
});

it('reports the only vacuum it has had', function (): void {
    $manual = CarbonImmutable::parse('2026-07-01 10:00:00');

    expect(statistic(vacuum: $manual)->lastVacuumedAt())->toEqual($manual);
});
