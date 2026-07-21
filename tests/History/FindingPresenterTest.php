<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\History\FindingPresenter;
use Heyosseus\Vacuum\History\MetricKind;
use Heyosseus\Vacuum\History\Models\Snapshot;
use Heyosseus\Vacuum\History\Trend;

function seededSnapshot(string $at): Snapshot
{
    return Snapshot::create([
        'connection' => 'pgsql',
        'taken_at' => CarbonImmutable::parse($at),
        'created_at' => CarbonImmutable::parse($at),
        'server_version' => 170000,
        'health_score' => 100,
        'grade' => 'A',
    ]);
}

function tableFinding(string $rule, string $table): Finding
{
    return new Finding(
        rule: $rule,
        subject: $table,
        severity: Severity::Warning,
        summary: 'the finding sentence',
        impact: 'impact',
        table: $table,
    );
}

function present(Finding $finding): mixed
{
    return app(FindingPresenter::class)->present([$finding])[0];
}

it('passes findings through untouched when history is off', function (): void {
    config()->set('vacuum.history.enabled', false);

    $view = present(tableFinding('wraparound', 'public.orders'));

    expect($view->trend)->toBe(Trend::Unknown)
        ->and($view->forecast)->toBeNull()
        ->and($view->intervalSummary)->toBeNull()
        ->and($view->summary())->toBe('the finding sentence');
});

it('lays a rising trend and a forecast over a tracked table finding', function (): void {
    config()->set('vacuum.history.forecast.minimum_snapshots', 3);
    config()->set('vacuum.thresholds.wraparound_xid_age_critical', 1_000);

    foreach ([100.0, 200.0, 300.0, 400.0] as $day => $value) {
        seededSnapshot('2026-04-0'.($day + 1).' 00:00:00')
            ->metrics()->create(['kind' => MetricKind::TableXidAge, 'object' => 'public.orders', 'value' => $value]);
    }

    $view = present(tableFinding('wraparound', 'public.orders'));

    expect($view->trend)->toBe(Trend::Rising)
        ->and($view->forecast)->not->toBeNull()
        ->and($view->forecast->days)->toBe(6);
});

it('forecasts a bloat finding against the size at which it becomes one', function (): void {
    config()->set('vacuum.history.forecast.minimum_snapshots', 3);
    config()->set('vacuum.thresholds.bloat_bytes', 1_000);

    foreach ([100.0, 200.0, 300.0, 400.0] as $day => $value) {
        seededSnapshot('2026-08-0'.($day + 1).' 00:00:00')
            ->metrics()->create(['kind' => MetricKind::TableBloatBytes, 'object' => 'public.orders', 'value' => $value]);
    }

    $view = present(tableFinding('table-bloat', 'public.orders'));

    expect($view->forecast)->not->toBeNull()
        ->and($view->forecast->days)->toBe(6);
});

it('replaces the cache-hit sentence with the interval figure', function (): void {
    seededSnapshot('2026-01-01 00:00:00')
        ->metrics()->create(['kind' => MetricKind::DbCache, 'object' => 'database', 'value' => 1_000.0, 'value2' => 100.0]);
    seededSnapshot('2026-01-01 01:00:00')
        ->metrics()->create(['kind' => MetricKind::DbCache, 'object' => 'database', 'value' => 2_000.0, 'value2' => 150.0]);

    $finding = new Finding(
        rule: 'cache-hit-ratio',
        subject: 'database',
        severity: Severity::Warning,
        summary: 'the lifetime sentence',
        impact: 'impact',
    );

    $view = present($finding);

    expect($view->intervalSummary)->toContain('over the last 1 hour')
        ->and($view->summary())->toBe($view->intervalSummary);
});

it('replaces the slow-statement sentence with the interval figure', function (): void {
    seededSnapshot('2026-01-01 00:00:00')
        ->metrics()->create(['kind' => MetricKind::Statement, 'object' => '42', 'value' => 1_000.0, 'value2' => 100.0]);
    seededSnapshot('2026-01-01 01:00:00')
        ->metrics()->create(['kind' => MetricKind::Statement, 'object' => '42', 'value' => 3_000.0, 'value2' => 200.0]);

    $finding = new Finding(
        rule: 'slow-statement',
        subject: 'query 42',
        severity: Severity::Critical,
        summary: 'the lifetime sentence',
        impact: 'impact',
    );

    $view = present($finding);

    expect($view->intervalSummary)->toContain('over the last 1 hour')
        ->and($view->intervalSummary)->toContain('20 ms');
});

it('leaves a slow-statement finding alone when there is no interval cost yet', function (): void {
    // Only one statement snapshot exists, so there is no interval to difference.
    seededSnapshot('2026-01-01 00:00:00')
        ->metrics()->create(['kind' => MetricKind::Statement, 'object' => '42', 'value' => 1_000.0, 'value2' => 100.0]);

    $finding = new Finding(
        rule: 'slow-statement',
        subject: 'query 42',
        severity: Severity::Critical,
        summary: 'the lifetime sentence',
        impact: 'impact',
    );

    expect(present($finding)->intervalSummary)->toBeNull();
});

it('leaves a finding alone when history has no interval figure for it yet', function (): void {
    // History is on, but only one cache snapshot exists, so there is no interval.
    seededSnapshot('2026-01-01 00:00:00')
        ->metrics()->create(['kind' => MetricKind::DbCache, 'object' => 'database', 'value' => 1_000.0, 'value2' => 100.0]);

    $finding = new Finding(
        rule: 'cache-hit-ratio',
        subject: 'database',
        severity: Severity::Warning,
        summary: 'the lifetime sentence',
        impact: 'impact',
    );

    expect(present($finding)->intervalSummary)->toBeNull();
});
