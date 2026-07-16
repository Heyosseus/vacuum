<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\ServerCapabilities;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Support\Facades\DB;

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

it('reads which libraries the server preloaded at startup, where the role may look', function (): void {
    // The probe has to bring the list home: CREATE EXTENSION alone cannot prove
    // pg_stat_statements works, only the preload list can. The server shows it
    // to pg_read_all_settings only, so the probe carries exactly what the role
    // was allowed to see -- no more, and nothing invented.
    $visible = DB::table('pg_settings')->where('name', 'shared_preload_libraries')->exists();

    $capabilities = app(ServerCapabilities::class)->probe();

    expect(array_key_exists('shared_preload_libraries', $capabilities->settings))->toBe($visible);
});

it('reports whether the role may read every session of the server', function (): void {
    expect(app(ServerCapabilities::class)->probe()->readsAllStatistics)->toBeTrue();
});

it('probes the server once, however many panels ask', function (): void {
    expect(app(Capabilities::class))->toBe(app(Capabilities::class));
});
