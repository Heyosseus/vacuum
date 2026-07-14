<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\DeadTuples;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\TableStatistic;

function table(int $live, int $dead, string $name = 'widgets', string $schema = 'public'): TableStatistic
{
    return new TableStatistic(
        schema: $schema,
        name: $name,
        liveTuples: $live,
        deadTuples: $dead,
        modificationsSinceAnalyze: 0,
        lastVacuum: null,
        lastAutovacuum: null,
        lastAnalyze: null,
        lastAutoanalyze: null,
    );
}

it('says nothing about a table whose dead tuples are within the threshold', function (): void {
    // 5% dead, against a 20% threshold.
    expect(app(DeadTuples::class)->inspect(table(live: 95_000, dead: 5_000)))->toBeNull();
});

it('warns about a table past the dead tuple threshold', function (): void {
    $finding = app(DeadTuples::class)->inspect(table(live: 70_000, dead: 30_000));

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe('public.widgets');
});

it('calls a table more dead than alive critical', function (): void {
    $finding = app(DeadTuples::class)->inspect(table(live: 40_000, dead: 60_000));

    expect($finding->severity)->toBe(Severity::Critical);
});

it('leaves small tables alone however dead they are', function (): void {
    // Entirely dead, but far too small to be worth anyone's attention.
    expect(app(DeadTuples::class)->inspect(table(live: 0, dead: 12)))->toBeNull();
});

it('offers the vacuum that would reclaim the space', function (): void {
    $finding = app(DeadTuples::class)->inspect(table(live: 70_000, dead: 30_000));

    expect($finding->remediation)->toBe('VACUUM ANALYZE "public"."widgets";');
});

it('quotes an identifier that would otherwise break the statement', function (): void {
    $finding = app(DeadTuples::class)->inspect(table(live: 70_000, dead: 30_000, name: 'my "odd" table'));

    expect($finding->remediation)->toBe('VACUUM ANALYZE "public"."my ""odd"" table";');
});

it('honours a threshold the application has tightened', function (): void {
    config()->set('vacuum.thresholds.dead_tuple_ratio', 0.02);

    // 5% dead: healthy by default, a problem once the threshold drops to 2%.
    expect(app(DeadTuples::class)->inspect(table(live: 95_000, dead: 5_000)))->not->toBeNull();
});

it('states what the bloat is costing', function (): void {
    $finding = app(DeadTuples::class)->inspect(table(live: 70_000, dead: 30_000));

    expect($finding->rule)->toBe('dead-tuples')
        ->and($finding->summary)->toContain('30.0%')
        ->and($finding->impact)->not->toBeEmpty();
});
