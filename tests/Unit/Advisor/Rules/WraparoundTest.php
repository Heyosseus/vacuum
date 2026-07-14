<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\Wraparound;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\TableStatistic;

function aging(int $xidAge, string $name = 'widgets'): TableStatistic
{
    return new TableStatistic(
        schema: 'public',
        name: $name,
        liveTuples: 1_000,
        deadTuples: 0,
        modificationsSinceAnalyze: 0,
        xidAge: $xidAge,
        lastVacuum: null,
        lastAutovacuum: null,
        lastAnalyze: null,
        lastAutoanalyze: null,
    );
}

it('says nothing about a table autovacuum is freezing on schedule', function (): void {
    expect(app(Wraparound::class)->inspect(aging(xidAge: 12_000_000)))->toBeNull();
});

it('warns about a table nothing has frozen in longer than the freeze horizon', function (): void {
    $finding = app(Wraparound::class)->inspect(aging(xidAge: 250_000_000));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('wraparound')
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe('public.widgets');
});

it('calls a table approaching the transaction limit critical', function (): void {
    $finding = app(Wraparound::class)->inspect(aging(xidAge: 1_500_000_000));

    expect($finding->severity)->toBe(Severity::Critical);
});

it('says how much of the transaction budget the table has spent', function (): void {
    // 250 million of the 2,147,483,647 transaction ids PostgreSQL can count.
    $finding = app(Wraparound::class)->inspect(aging(xidAge: 250_000_000));

    expect($finding->summary)->toContain('250,000,000')
        ->and($finding->summary)->toContain('11.6%');
});

it('warns that the database stops rather than slows', function (): void {
    $finding = app(Wraparound::class)->inspect(aging(xidAge: 250_000_000));

    expect($finding->impact)->toContain('single-user')
        ->and($finding->impact)->toContain('refuses');
});

it('offers the freeze that would advance the horizon', function (): void {
    $finding = app(Wraparound::class)->inspect(aging(xidAge: 250_000_000, name: 'my "odd" table'));

    expect($finding->remediation)->toBe('VACUUM (FREEZE, ANALYZE) "public"."my ""odd"" table";');
});

it('honours a freeze horizon the application has raised', function (): void {
    // A cluster whose autovacuum_freeze_max_age is 400 million should not be
    // warned at 250 million: autovacuum is not late yet.
    config()->set('vacuum.thresholds.wraparound_xid_age', 400_000_000);

    expect(app(Wraparound::class)->inspect(aging(xidAge: 250_000_000)))->toBeNull();
});
