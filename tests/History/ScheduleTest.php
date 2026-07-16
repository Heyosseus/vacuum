<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * @return list<string>
 */
function scheduledCommands(): array
{
    return array_map(
        static fn (Event $event): string => (string) ($event->command ?? ''),
        app(Schedule::class)->events(),
    );
}

function snapshotScheduled(): bool
{
    return collect(scheduledCommands())->contains(
        static fn (string $command): bool => str_contains($command, 'vacuum:snapshot'),
    );
}

it('schedules the snapshot command on the configured cadence when history is on', function (): void {
    expect(snapshotScheduled())->toBeTrue();
});

it('falls back to hourly for a cadence the scheduler does not recognise', function (): void {
    config()->set('vacuum.history.schedule', 'fortnightly-ish');

    // Registered all the same, rather than silently dropped: an unknown value should
    // not stop the snapshots.
    expect(snapshotScheduled())->toBeTrue();
});

it('registers no schedule when the cadence is left to the application', function (): void {
    config()->set('vacuum.history.schedule');

    expect(snapshotScheduled())->toBeFalse();
});
