<?php

declare(strict_types=1);

use Heyosseus\Vacuum\History\CollectedMetric;
use Heyosseus\Vacuum\History\MetricKind;
use Heyosseus\Vacuum\History\SnapshotMetrics;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
    DB::statement('CREATE TABLE crates (id serial PRIMARY KEY, label text)');
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 2000) i");
    DB::statement('ANALYZE crates');
    DB::statement('SELECT pg_stat_force_next_flush()');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
});

/**
 * @return list<CollectedMetric>
 */
function collectMetrics(): array
{
    return app(SnapshotMetrics::class)->collect();
}

function metricFor(MetricKind $kind, string $object): ?CollectedMetric
{
    return collect(collectMetrics())
        ->first(fn (CollectedMetric $metric): bool => $metric->kind === $kind && $metric->object === $object);
}

it('always collects the database cache counters', function (): void {
    $cache = metricFor(MetricKind::DbCache, 'database');

    expect($cache)->not->toBeNull()
        ->and($cache->value)->toBeGreaterThanOrEqual(0.0)
        ->and($cache->value2)->toBeGreaterThanOrEqual(0.0);
});

it('collects size, freeze age and dead tuples for a table above the floor', function (): void {
    // Floor of zero admits every table, so the small test table qualifies.
    config()->set('vacuum.history.metric_table_min_bytes', 0);

    expect(metricFor(MetricKind::TableBloatBytes, 'public.crates'))->not->toBeNull()
        ->and(metricFor(MetricKind::TableXidAge, 'public.crates'))->not->toBeNull()
        ->and(metricFor(MetricKind::TableDeadTuples, 'public.crates'))->not->toBeNull();
});

it('leaves a small, young table out when the floor is high', function (): void {
    config()->set('vacuum.history.metric_table_min_bytes', 1024 * 1024 * 1024 * 1024);

    // Neither large enough for its space to matter nor old enough for its freeze age to.
    expect(metricFor(MetricKind::TableBloatBytes, 'public.crates'))->toBeNull()
        ->and(metricFor(MetricKind::TableDeadTuples, 'public.crates'))->toBeNull()
        ->and(metricFor(MetricKind::TableXidAge, 'public.crates'))->toBeNull();
});

it('keeps a young table only for its freeze age when the watch line is low', function (): void {
    config()->set('vacuum.history.metric_table_min_bytes', 1024 * 1024 * 1024 * 1024);
    // Watch line is half of this, so any freeze age at all keeps the xid metric.
    config()->set('vacuum.thresholds.wraparound_xid_age', 2);

    expect(metricFor(MetricKind::TableXidAge, 'public.crates'))->not->toBeNull()
        ->and(metricFor(MetricKind::TableBloatBytes, 'public.crates'))->toBeNull();
});

it('collects a metric for each watched statement shape', function (): void {
    if (! statStatementsInstalled()) {
        expect(true)->toBeTrue();

        return;
    }

    DB::select('SELECT 1 AS warm_the_statements');

    $statements = collect(collectMetrics())
        ->filter(fn (CollectedMetric $metric): bool => $metric->kind === MetricKind::Statement);

    expect($statements)->not->toBeEmpty();
});
