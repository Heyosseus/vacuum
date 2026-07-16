<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\History\History;
use Heyosseus\Vacuum\History\MetricKind;
use Heyosseus\Vacuum\History\Models\Snapshot;
use Heyosseus\Vacuum\History\Trend;

function snap(string $at, int $score = 100): Snapshot
{
    return Snapshot::create([
        'connection' => 'pgsql',
        'taken_at' => CarbonImmutable::parse($at),
        'created_at' => CarbonImmutable::parse($at),
        'server_version' => 170000,
        'health_score' => $score,
        'grade' => 'A',
    ]);
}

function metric(Snapshot $snapshot, MetricKind $kind, string $object, float $value, ?float $value2 = null): void
{
    $snapshot->metrics()->create([
        'kind' => $kind,
        'object' => $object,
        'value' => $value,
        'value2' => $value2,
    ]);
}

function history(): History
{
    return app(History::class);
}

it('reads the cache-hit ratio over the last interval, not the whole life of the server', function (): void {
    metric(snap('2026-01-01 00:00:00'), MetricKind::DbCache, 'database', 1_000.0, 100.0);
    metric(snap('2026-01-01 01:00:00'), MetricKind::DbCache, 'database', 2_000.0, 150.0);

    // 1000 hits and 50 reads happened between the two: 1000 / 1050.
    expect(history()->intervalCacheHitRatio())->toBe(1_000.0 / 1_050.0);
});

it('has no interval cache-hit ratio until two snapshots exist', function (): void {
    metric(snap('2026-01-01 00:00:00'), MetricKind::DbCache, 'database', 1_000.0, 100.0);

    expect(history()->intervalCacheHitRatio())->toBeNull();
});

it('refuses an interval cache-hit ratio when the counters reset between snapshots', function (): void {
    metric(snap('2026-01-01 00:00:00'), MetricKind::DbCache, 'database', 5_000.0, 500.0);
    metric(snap('2026-01-01 01:00:00'), MetricKind::DbCache, 'database', 100.0, 10.0);

    // The later counter is smaller than the earlier one, so the stats were reset;
    // there is no honest difference to report.
    expect(history()->intervalCacheHitRatio())->toBeNull();
});

it('reads a statement cost over the last interval', function (): void {
    metric(snap('2026-01-01 00:00:00'), MetricKind::Statement, '42', 1_000.0, 100.0);
    metric(snap('2026-01-01 01:00:00'), MetricKind::Statement, '42', 3_000.0, 200.0);

    // 2000ms across 100 calls added in the interval: a 20ms mean.
    expect(history()->intervalStatementCost('42'))->toBe([
        'total_ms' => 2_000.0,
        'calls' => 100.0,
        'mean_ms' => 20.0,
    ]);
});

it('has no interval statement cost when no calls were added, or the stats reset', function (): void {
    metric(snap('2026-01-01 00:00:00'), MetricKind::Statement, '42', 1_000.0, 100.0);
    metric(snap('2026-01-01 01:00:00'), MetricKind::Statement, '42', 1_000.0, 100.0);

    expect(history()->intervalStatementCost('42'))->toBeNull()
        ->and(history()->intervalStatementCost('99'))->toBeNull();
});

it('says which way a metric is moving', function (): void {
    foreach ([100.0, 200.0, 300.0] as $day => $value) {
        metric(snap('2026-01-0'.($day + 1).' 00:00:00'), MetricKind::TableXidAge, 'public.orders', $value);
    }

    expect(history()->direction(MetricKind::TableXidAge, 'public.orders'))->toBe(Trend::Rising);
});

it('says a falling metric is easing and a steady one is flat', function (): void {
    foreach ([300.0, 200.0, 100.0] as $day => $value) {
        metric(snap('2026-02-0'.($day + 1).' 00:00:00'), MetricKind::TableBloatBytes, 'public.a', $value);
    }
    foreach ([500.0, 500.0, 500.0] as $day => $value) {
        metric(snap('2026-03-0'.($day + 1).' 00:00:00'), MetricKind::TableBloatBytes, 'public.b', $value);
    }

    expect(history()->direction(MetricKind::TableBloatBytes, 'public.a'))->toBe(Trend::Falling)
        ->and(history()->direction(MetricKind::TableBloatBytes, 'public.b'))->toBe(Trend::Flat);
});

it('cannot say which way a metric moves from a single point', function (): void {
    metric(snap('2026-01-01 00:00:00'), MetricKind::TableXidAge, 'public.orders', 100.0);

    expect(history()->direction(MetricKind::TableXidAge, 'public.orders'))->toBe(Trend::Unknown);
});

it('cannot read a direction when every snapshot shares one instant', function (): void {
    // Two points on the same vertical: there is no line through them to take a slope from.
    metric(snap('2026-01-01 00:00:00'), MetricKind::TableXidAge, 'public.x', 100.0);
    metric(snap('2026-01-01 00:00:00'), MetricKind::TableXidAge, 'public.x', 200.0);

    expect(history()->direction(MetricKind::TableXidAge, 'public.x'))->toBe(Trend::Unknown);
});

it('projects when a climbing monotonic metric will cross a threshold', function (): void {
    config()->set('vacuum.history.forecast.minimum_snapshots', 3);

    foreach ([100.0, 200.0, 300.0, 400.0] as $day => $value) {
        metric(snap('2026-04-0'.($day + 1).' 00:00:00'), MetricKind::TableXidAge, 'public.orders', $value);
    }

    $forecast = history()->forecast(MetricKind::TableXidAge, 'public.orders', 1_000.0);

    // Climbing 100 a day from 400, it reaches 1000 six days after the last point.
    expect($forecast)->not->toBeNull()
        ->and($forecast->days)->toBe(6);
});

it('draws no forecast when it should not', function (): void {
    config()->set('vacuum.history.forecast.minimum_snapshots', 3);

    // A non-monotonic kind is never forecast, whatever it is doing.
    foreach ([100.0, 200.0, 300.0, 400.0] as $day => $value) {
        metric(snap('2026-05-0'.($day + 1).' 00:00:00'), MetricKind::TableDeadTuples, 'public.a', $value);
    }

    expect(history()->forecast(MetricKind::TableDeadTuples, 'public.a', 1_000.0))->toBeNull()
        // Too few points to trust.
        ->and(history()->forecast(MetricKind::TableXidAge, 'public.none', 1_000.0))->toBeNull();
});

it('will not forecast a value already past the line, or a scattered climb', function (): void {
    config()->set('vacuum.history.forecast.minimum_snapshots', 3);

    foreach ([100.0, 200.0, 300.0, 400.0] as $day => $value) {
        metric(snap('2026-06-0'.($day + 1).' 00:00:00'), MetricKind::TableXidAge, 'public.past', $value);
    }
    // Last point 400 is already over a 300 line: that is a finding, not a forecast.
    expect(history()->forecast(MetricKind::TableXidAge, 'public.past', 300.0))->toBeNull();

    foreach ([100.0, 900.0, 200.0, 800.0] as $day => $value) {
        metric(snap('2026-07-0'.($day + 1).' 00:00:00'), MetricKind::TableXidAge, 'public.noisy', $value);
    }
    // A line through scattered points does not fit them well enough to project.
    expect(history()->forecast(MetricKind::TableXidAge, 'public.noisy', 5_000.0))->toBeNull();
});

it('will not forecast a line the trend already crossed before its last point', function (): void {
    config()->set('vacuum.history.forecast.minimum_snapshots', 3);

    // The last reading dips below the climb. The fitted line is already over the 500
    // threshold at that point even though the reading is not, so the crossing sits at
    // or before the last point: there is nothing left to project forward to.
    foreach ([100.0, 200.0, 300.0, 400.0, 500.0, 450.0] as $day => $value) {
        metric(snap('2026-09-0'.($day + 1).' 00:00:00'), MetricKind::TableXidAge, 'public.dip', $value);
    }

    expect(history()->forecast(MetricKind::TableXidAge, 'public.dip', 500.0))->toBeNull();
});

it('finds what is newly wrong since the previous snapshot', function (): void {
    $previous = snap('2026-01-01 00:00:00');
    $previous->findings()->create(['rule' => 'wraparound', 'subject' => 'public.a', 'severity' => 'critical', 'summary' => 's']);

    $latest = snap('2026-01-01 01:00:00');
    $latest->findings()->create(['rule' => 'wraparound', 'subject' => 'public.a', 'severity' => 'critical', 'summary' => 's']);
    $latest->findings()->create(['rule' => 'dead-tuples', 'subject' => 'public.b', 'severity' => 'warning', 'summary' => 's']);

    $new = history()->newFindings();

    expect($new)->toHaveCount(1)
        ->and($new[0]->subject)->toBe('public.b');
});

it('finds what has cleared since the previous snapshot', function (): void {
    $previous = snap('2026-01-01 00:00:00');
    $previous->findings()->create(['rule' => 'blocked-session', 'subject' => 'pid 7', 'severity' => 'warning', 'summary' => 's']);

    snap('2026-01-01 01:00:00');

    $cleared = history()->clearedFindings();

    expect($cleared)->toHaveCount(1)
        ->and($cleared[0]->subject)->toBe('pid 7');
});

it('treats every finding as new when there is no snapshot before it', function (): void {
    $only = snap('2026-01-01 00:00:00');
    $only->findings()->create(['rule' => 'wraparound', 'subject' => 'public.a', 'severity' => 'critical', 'summary' => 's']);

    expect(history()->newFindings())->toHaveCount(1)
        ->and(history()->clearedFindings())->toBe([]);
});

it('has nothing to diff, chart or read when no snapshot has been taken', function (): void {
    expect(history()->newFindings())->toBe([])
        ->and(history()->clearedFindings())->toBe([])
        ->and(history()->scores())->toBe([])
        ->and(history()->series(MetricKind::TableXidAge, 'public.a'))->toBe([])
        ->and(history()->intervalCacheHitRatio())->toBeNull()
        ->and(history()->latest())->toBeNull();
});

it('reads the health score at each snapshot for the line', function (): void {
    snap('2026-01-01 00:00:00', 90);
    snap('2026-01-01 01:00:00', 70);

    $scores = history()->scores();

    expect($scores)->toHaveCount(2)
        ->and($scores[0]['score'])->toBe(90)
        ->and($scores[1]['score'])->toBe(70)
        ->and($scores[0]['at'])->toBeInstanceOf(CarbonImmutable::class);
});

it('knows whether history is switched on', function (): void {
    expect(history()->enabled())->toBeTrue();
});
