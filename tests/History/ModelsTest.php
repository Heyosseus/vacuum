<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\History\MetricKind;
use Heyosseus\Vacuum\History\Models\Snapshot;

it('relates a finding and a metric back to the snapshot they belong to', function (): void {
    $snapshot = Snapshot::create([
        'connection' => 'pgsql',
        'taken_at' => CarbonImmutable::parse('2026-01-01 00:00:00'),
        'created_at' => CarbonImmutable::parse('2026-01-01 00:00:00'),
        'server_version' => 170000,
        'health_score' => 88,
        'grade' => 'B',
    ]);

    $finding = $snapshot->findings()->create([
        'rule' => 'wraparound', 'subject' => 'public.a', 'severity' => 'critical', 'summary' => 's',
    ]);
    $metric = $snapshot->metrics()->create([
        'kind' => MetricKind::TableXidAge, 'object' => 'public.a', 'value' => 42.0,
    ]);

    expect($finding->snapshot->id)->toBe($snapshot->id)
        ->and($metric->snapshot->id)->toBe($snapshot->id)
        // The kind round-trips through the database as its enum, not its string.
        ->and($metric->kind)->toBe(MetricKind::TableXidAge)
        ->and($snapshot->taken_at)->toBeInstanceOf(CarbonImmutable::class);
});
