<?php

declare(strict_types=1);

use Heyosseus\Vacuum\History\Models\Snapshot;

it('records a snapshot and reports what it found', function (): void {
    $this->artisan('vacuum:snapshot')
        ->assertSuccessful()
        ->expectsOutputToContain('Snapshot recorded');

    expect(Snapshot::count())->toBe(1);
});

it('refuses, and records nothing, when Vacuum is switched off', function (): void {
    // A snapshot taken while Vacuum is off would capture an empty database and poison
    // every trend built on it.
    config()->set('vacuum.enabled', false);

    $this->artisan('vacuum:snapshot')
        ->assertExitCode(2)
        ->expectsOutputToContain('Vacuum is disabled');

    expect(Snapshot::count())->toBe(0);
});

it('refuses, and records nothing, when history is switched off', function (): void {
    config()->set('vacuum.history.enabled', false);

    $this->artisan('vacuum:snapshot')
        ->assertExitCode(2)
        ->expectsOutputToContain('Vacuum history is off');

    expect(Snapshot::count())->toBe(0);
});
