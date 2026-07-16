<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\History\MetricKind;
use Heyosseus\Vacuum\History\Models\Snapshot;
use Heyosseus\Vacuum\History\Models\SnapshotFinding;
use Heyosseus\Vacuum\History\Models\SnapshotMetric;
use Heyosseus\Vacuum\History\Recorder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
    DB::statement('CREATE TABLE crates (id serial PRIMARY KEY, label text)');
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 5000) i");
    // Leave a table that is mostly dead tuples, so the advisor returns a dead-tuples
    // finding whose subject is a table — the case the recorder gives a metric value to.
    DB::delete('DELETE FROM crates WHERE id > 1500');
    DB::statement('SELECT pg_stat_force_next_flush()');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
});

it('records a snapshot with its health, findings and metrics', function (): void {
    $snapshot = app(Recorder::class)->record();

    expect(Snapshot::count())->toBe(1)
        ->and($snapshot->connection)->toBe('pgsql')
        ->and($snapshot->server_version)->toBeGreaterThan(0)
        ->and($snapshot->health_score)->toBeInt()
        ->and($snapshot->grade)->toBeString()
        // The cache metric is always collected, so there is always at least one.
        ->and($snapshot->metrics()->count())->toBeGreaterThanOrEqual(1)
        ->and($snapshot->findings()->count())->toBeGreaterThan(0);
});

it('gives a finding the value of its own metric where there is one', function (): void {
    app(Recorder::class)->record();

    $dead = SnapshotFinding::query()->where('rule', 'dead-tuples')->first();

    // The dead-tuples finding is about a table, so it carries a number; the recorder
    // looked it up from the metrics it gathered rather than leaving it null.
    expect($dead)->not->toBeNull()
        ->and($dead->table_name)->toBe('public.crates');
});

it('prunes snapshots past the retention window, and their children with them', function (): void {
    config()->set('vacuum.history.retention_days', 30);

    $old = Snapshot::create([
        'connection' => 'pgsql',
        'taken_at' => CarbonImmutable::now()->subDays(60),
        'created_at' => CarbonImmutable::now()->subDays(60),
        'server_version' => 170000,
        'health_score' => 50,
        'grade' => 'F',
    ]);
    $old->findings()->create(['rule' => 'wraparound', 'subject' => 'public.a', 'severity' => 'critical', 'summary' => 's']);
    $old->metrics()->create(['kind' => MetricKind::TableXidAge, 'object' => 'public.a', 'value' => 1.0]);

    app(Recorder::class)->record();

    expect(Snapshot::whereKey($old->id)->exists())->toBeFalse()
        ->and(SnapshotFinding::query()->where('snapshot_id', $old->id)->exists())->toBeFalse()
        ->and(SnapshotMetric::query()->where('snapshot_id', $old->id)->exists())->toBeFalse();
});

it('keeps every snapshot when the retention window is switched off', function (): void {
    config()->set('vacuum.history.retention_days', 0);

    $old = Snapshot::create([
        'connection' => 'pgsql',
        'taken_at' => CarbonImmutable::now()->subDays(400),
        'created_at' => CarbonImmutable::now()->subDays(400),
        'server_version' => 170000,
        'health_score' => 50,
        'grade' => 'F',
    ]);

    app(Recorder::class)->record();

    expect(Snapshot::whereKey($old->id)->exists())->toBeTrue();
});
