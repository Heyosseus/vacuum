<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\MultixactWraparound;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\TableStatistic;

function locking(int $mxidAge, int $xidAge = 1_000, string $name = 'widgets'): TableStatistic
{
    return new TableStatistic(
        schema: 'public',
        name: $name,
        liveTuples: 1_000,
        deadTuples: 0,
        modificationsSinceAnalyze: 0,
        xidAge: $xidAge,
        mxidAge: $mxidAge,
        lastVacuum: null,
        lastAutovacuum: null,
        lastAnalyze: null,
        lastAutoanalyze: null,
    );
}

it('says nothing about a table autovacuum is freezing on schedule', function (): void {
    expect(app(MultixactWraparound::class)->inspect(locking(mxidAge: 12_000_000)))->toBeNull();
});

it('warns about a table nothing has frozen in longer than the multixact freeze horizon', function (): void {
    $finding = app(MultixactWraparound::class)->inspect(locking(mxidAge: 450_000_000));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('multixact-wraparound')
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe('public.widgets');
});

it('calls a table approaching the multixact limit critical', function (): void {
    $finding = app(MultixactWraparound::class)->inspect(locking(mxidAge: 1_500_000_000));

    expect($finding->severity)->toBe(Severity::Critical);
});

// The whole reason this rule exists: the two clocks are independent, and a table
// can be perfectly healthy on one while the cluster is stopping because of the
// other. A table that is freezing its transaction ids on schedule must still be
// reported when its multixact age has run away.
it('reports a table whose transaction age is healthy but whose multixact age is not', function (): void {
    $finding = app(MultixactWraparound::class)->inspect(locking(mxidAge: 450_000_000, xidAge: 1_000));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('multixact-wraparound');
});

it('says how much of the multixact budget the table has spent', function (): void {
    // 450 million of the 2,147,483,647 multixact ids PostgreSQL can count.
    $finding = app(MultixactWraparound::class)->inspect(locking(mxidAge: 450_000_000));

    expect($finding->summary)->toContain('450,000,000')
        ->and($finding->summary)->toContain('21.0%');
});

it('explains that row locking is what creates multixacts', function (): void {
    $finding = app(MultixactWraparound::class)->inspect(locking(mxidAge: 450_000_000));

    expect($finding->impact)->toContain('single-user')
        ->and($finding->impact)->toContain('refuses');
});

it('offers the freeze that would advance the multixact horizon', function (): void {
    $finding = app(MultixactWraparound::class)->inspect(locking(mxidAge: 450_000_000, name: 'my "odd" table'));

    expect($finding->remediation)->toBe('VACUUM (FREEZE, ANALYZE) "public"."my ""odd"" table";');
});

it('drills down into the oldest multixact ages in the cluster', function (): void {
    $finding = app(MultixactWraparound::class)->inspect(locking(mxidAge: 450_000_000));

    expect($finding->query)->toContain('mxid_age(relminmxid)');
});

it('honours a multixact freeze horizon the application has raised', function (): void {
    // A cluster whose autovacuum_multixact_freeze_max_age is 800 million should not
    // be warned at 450 million: autovacuum is not late yet.
    config()->set('vacuum.thresholds.wraparound_mxid_age', 800_000_000);

    expect(app(MultixactWraparound::class)->inspect(locking(mxidAge: 450_000_000)))->toBeNull();
});

it('falls back to the default horizons when the configuration is nonsense', function (): void {
    config()->set('vacuum.thresholds.wraparound_mxid_age', 'not a number');
    config()->set('vacuum.thresholds.wraparound_mxid_age_critical', 'not a number');

    $finding = app(MultixactWraparound::class)->inspect(locking(mxidAge: 450_000_000));

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Warning);
});
