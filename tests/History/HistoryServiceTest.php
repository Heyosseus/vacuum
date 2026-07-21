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

    // 1000 hits and 50 reads happened between the two: 1000 / 1050, over the hour
    // that actually separates them.
    expect(history()->intervalCacheHitRatio())->toBe([
        'ratio' => 1_000.0 / 1_050.0,
        'seconds' => 3_600.0,
    ]);
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

    // 2000ms across 100 calls added in the interval: a 20ms mean, over an hour.
    expect(history()->intervalStatementCost('42'))->toBe([
        'total_ms' => 2_000.0,
        'calls' => 100.0,
        'mean_ms' => 20.0,
        'seconds' => 3_600.0,
    ]);
});

it('reports the span a statement interval actually covers, not the snapshot cadence', function (): void {
    // A snapshot keeps only the fifty costliest statements and that ranking
    // reshuffles, so a query can drop out of the list and reappear hours later.
    // The difference between its two stored rows is still a valid difference --
    // it is two counters subtracted -- but it covers five hours, and calling that
    // "the last interval" on an hourly schedule is a figure off by five times.
    metric(snap('2026-01-01 09:00:00'), MetricKind::Statement, '42', 1_000.0, 100.0);
    metric(snap('2026-01-01 10:00:00'), MetricKind::DbCache, 'database', 1.0, 1.0);
    metric(snap('2026-01-01 14:00:00'), MetricKind::Statement, '42', 3_000.0, 200.0);

    expect(history()->intervalStatementCost('42')['seconds'])->toBe(18_000.0);
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

it('forecasts only the climb since the last freeze, not a line drawn across it', function (): void {
    config()->set('vacuum.history.forecast.minimum_snapshots', 3);

    // The sawtooth the forecast used to be blind to. age(relfrozenxid) resets to
    // nearly nothing every time a table is frozen, so a series that froze early
    // and then fell behind is two unrelated climbs -- and a straight line through
    // both of them fits *well*, clears the r-squared floor, and reports a
    // crossing further away than the truth. Optimism is the one direction a
    // wraparound forecast must not err in.
    //
    // Here: one point at 900, then a freeze, then a clean climb of 100 a day. The
    // honest answer comes from the climb alone -- from 400, six days to 1000.
    // Fitted across the drop, the line is much shallower and the date much later.
    $values = [900.0, 100.0, 200.0, 300.0, 400.0];

    foreach ($values as $day => $value) {
        metric(snap('2026-08-0'.($day + 1).' 00:00:00'), MetricKind::TableXidAge, 'public.frozen', $value);
    }

    $forecast = history()->forecast(MetricKind::TableXidAge, 'public.frozen', 1_000.0);

    expect($forecast)->not->toBeNull()
        ->and($forecast->days)->toBe(6);
});

it('refuses a forecast when the climb since the last reset is too short to trust', function (): void {
    config()->set('vacuum.history.forecast.minimum_snapshots', 3);

    // Ninety days of history and a freeze yesterday leaves two points to reason
    // from, however long the series is in total. Counting before the cut was what
    // let a long history stand in for a long climb.
    foreach ([100.0, 200.0, 300.0, 400.0, 10.0, 20.0] as $day => $value) {
        metric(snap('2026-09-0'.($day + 1).' 00:00:00'), MetricKind::TableXidAge, 'public.justfroze', $value);
    }

    expect(history()->forecast(MetricKind::TableXidAge, 'public.justfroze', 1_000.0))->toBeNull();
});

it('does not mistake ordinary wobble for a reset', function (): void {
    config()->set('vacuum.history.forecast.minimum_snapshots', 3);

    // A metric that dips a little and carries on climbing has not been reset, and
    // cutting the series there would throw away most of the evidence.
    foreach ([100.0, 199.0, 300.0, 400.0] as $day => $value) {
        metric(snap('2026-10-0'.($day + 1).' 00:00:00'), MetricKind::TableXidAge, 'public.wobbly', $value);
    }

    expect(history()->forecast(MetricKind::TableXidAge, 'public.wobbly', 1_000.0))->not->toBeNull();
});

it('refuses a forecast for a metric that is not climbing at all', function (): void {
    config()->set('vacuum.history.forecast.minimum_snapshots', 3);

    // Flat is not a climb, and a line with no slope crosses nothing. Silence is
    // the only honest output.
    foreach ([500.0, 500.0, 500.0, 500.0] as $day => $value) {
        metric(snap('2026-11-0'.($day + 1).' 00:00:00'), MetricKind::TableXidAge, 'public.steady', $value);
    }

    expect(history()->forecast(MetricKind::TableXidAge, 'public.steady', 1_000.0))->toBeNull();
});
