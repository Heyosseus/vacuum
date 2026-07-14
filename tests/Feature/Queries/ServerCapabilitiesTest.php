<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\ServerCapabilities;
use Heyosseus\Vacuum\Values\Capabilities;

it('asks the server what it can do', function (): void {
    $capabilities = app(ServerCapabilities::class)->probe();

    expect($capabilities->majorVersion())->toBeGreaterThanOrEqual(14)
        ->and($capabilities->has('plpgsql'))->toBeTrue()
        ->and($capabilities->has('an_extension_nobody_installs'))->toBeFalse();
});

it('reports the statistics settings the panels depend on', function (): void {
    $capabilities = app(ServerCapabilities::class)->probe();

    expect($capabilities->enabled('track_counts'))->toBeTrue()
        ->and($capabilities->settings)->toHaveKey('track_io_timing');
});

it('reports whether the role may read every session of the server', function (): void {
    expect(app(ServerCapabilities::class)->probe()->readsAllStatistics)->toBeTrue();
});

it('probes the server once, however many panels ask', function (): void {
    expect(app(Capabilities::class))->toBe(app(Capabilities::class));
});
