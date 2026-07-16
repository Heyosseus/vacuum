<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\History\MetricKind;
use Heyosseus\Vacuum\History\Models\Snapshot;
use Heyosseus\Vacuum\Vacuum;
use Illuminate\Http\Request;

beforeEach(function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);
});

it('shows the empty state before any snapshot has been taken', function (): void {
    $this->get(route('vacuum.history'))
        ->assertOk()
        ->assertSee('No snapshots yet');
});

it('draws the health line and the sections once there is history', function (): void {
    // The second-to-last snapshot carries a finding the last one does not, so the diff
    // between the two most recent shows it as cleared.
    $previous = Snapshot::create([
        'connection' => 'pgsql', 'taken_at' => CarbonImmutable::parse('2026-04-01 00:00:00'),
        'created_at' => CarbonImmutable::parse('2026-04-01 00:00:00'),
        'server_version' => 170000, 'health_score' => 70, 'grade' => 'C',
    ]);
    $previous->findings()->create(['rule' => 'blocked-session', 'subject' => 'pid 9', 'severity' => 'warning', 'summary' => 's']);
    $previous->metrics()->create(['kind' => MetricKind::TableXidAge, 'object' => 'public.orders', 'value' => 100.0]);

    Snapshot::create([
        'connection' => 'pgsql', 'taken_at' => CarbonImmutable::parse('2026-04-02 00:00:00'),
        'created_at' => CarbonImmutable::parse('2026-04-02 00:00:00'),
        'server_version' => 170000, 'health_score' => 90, 'grade' => 'A',
    ])->metrics()->create(['kind' => MetricKind::TableXidAge, 'object' => 'public.orders', 'value' => 200.0]);

    $this->get(route('vacuum.history'))
        ->assertOk()
        ->assertSee('Health over time')
        ->assertSee('Cleared since the previous snapshot');
});

it('is linked from the dashboard navigation when history is on', function (): void {
    $this->get(route('vacuum.dashboard'))
        ->assertOk()
        ->assertSee('history');
});
