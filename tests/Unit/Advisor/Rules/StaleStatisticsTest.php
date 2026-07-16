<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Advisor\Rules\StaleStatistics;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\TableStatistic;

function analyzed(int $live, int $modifications, ?CarbonImmutable $at = null, string $name = 'widgets'): TableStatistic
{
    return new TableStatistic(
        schema: 'public',
        name: $name,
        liveTuples: $live,
        deadTuples: 0,
        modificationsSinceAnalyze: $modifications,
        xidAge: 0,
        mxidAge: 0,
        lastVacuum: null,
        lastAutovacuum: null,
        lastAnalyze: null,
        lastAutoanalyze: $at ?? CarbonImmutable::parse('2026-07-01 10:00:00'),
    );
}

function neverAnalyzed(int $live, int $modifications = 0): TableStatistic
{
    return new TableStatistic(
        schema: 'public',
        name: 'widgets',
        liveTuples: $live,
        deadTuples: 0,
        modificationsSinceAnalyze: $modifications,
        xidAge: 0,
        mxidAge: 0,
        lastVacuum: null,
        lastAutovacuum: null,
        lastAnalyze: null,
        lastAutoanalyze: null,
    );
}

it('says nothing about a table autoanalyze is keeping up with', function (): void {
    // 5,000 modifications against a million rows, and analyzed since.
    expect(app(StaleStatistics::class)->inspect(analyzed(live: 1_000_000, modifications: 5_000)))->toBeNull();
});

it('says nothing about a busy table whose statistics have kept pace', function (): void {
    // 80,000 modifications is a lot of writing, and well past the minimum. Against
    // ten million rows it is 0.8% of the table, and the planner's numbers still
    // describe it. Volume is not staleness.
    expect(app(StaleStatistics::class)->inspect(analyzed(live: 10_000_000, modifications: 80_000)))->toBeNull();
});

it('leaves a small table alone however much of it has changed', function (): void {
    // Every row of a 200 row table has changed. Nobody plans a query badly enough
    // over 200 rows for it to matter, and autoanalyze will get to it.
    expect(app(StaleStatistics::class)->inspect(analyzed(live: 200, modifications: 200)))->toBeNull();
});

it('warns about a table whose statistics the writes have overtaken', function (): void {
    // 300,000 modifications against a million rows: 30%, against a 20% threshold.
    $finding = app(StaleStatistics::class)->inspect(analyzed(live: 1_000_000, modifications: 300_000));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('stale-statistics')
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe('public.widgets')
        ->and($finding->summary)->toContain('300,000')
        ->and($finding->summary)->toContain('30.0%');
});

it('calls statistics describing a table that no longer exists critical', function (): void {
    // More rows have changed than the table holds. Whatever the planner believes
    // about this table, it is not describing this table.
    $finding = app(StaleStatistics::class)->inspect(analyzed(live: 100_000, modifications: 140_000));

    expect($finding->severity)->toBe(Severity::Critical);
});

it('warns about a table nothing has ever analyzed', function (): void {
    $finding = app(StaleStatistics::class)->inspect(neverAnalyzed(live: 500_000));

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->summary)->toContain('never');
});

it('does not complain about a table nobody has ever written to', function (): void {
    // Never analyzed, but empty. There is nothing for the planner to be wrong about.
    expect(app(StaleStatistics::class)->inspect(neverAnalyzed(live: 0)))->toBeNull();
});

it('says the planner is guessing rather than that a number is high', function (): void {
    $finding = app(StaleStatistics::class)->inspect(analyzed(live: 1_000_000, modifications: 300_000));

    expect($finding->impact)->toContain('planner')
        ->and($finding->evidence)->toContain('2026-07-01');
});

it('offers the analyze that would refresh them', function (): void {
    $finding = app(StaleStatistics::class)->inspect(
        analyzed(live: 1_000_000, modifications: 300_000, name: 'my "odd" table'),
    );

    expect($finding->remediation)->toBe('ANALYZE "public"."my ""odd"" table";');
});

it('honours a staleness threshold the application has tightened', function (): void {
    config()->set('vacuum.thresholds.stale_statistics_ratio', 0.01);

    expect(app(StaleStatistics::class)->inspect(analyzed(live: 1_000_000, modifications: 50_000)))->not->toBeNull();
});

it('honours a minimum the application has raised', function (): void {
    config()->set('vacuum.thresholds.stale_statistics_minimum', 1_000_000);

    expect(app(StaleStatistics::class)->inspect(analyzed(live: 1_000_000, modifications: 300_000)))->toBeNull();
});
