<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Values\Capabilities;

it('reads the major version out of the version number the server reports', function (): void {
    $capabilities = new Capabilities(
        serverVersion: 170_005,
        extensions: [],
        settings: [],
        readsAllStatistics: false,
    );

    expect($capabilities->majorVersion())->toBe(17);
});

it('knows whether the server is new enough for a feature', function (): void {
    $capabilities = new Capabilities(
        serverVersion: 160_009,
        extensions: [],
        settings: [],
        readsAllStatistics: false,
    );

    expect($capabilities->atLeast(16))->toBeTrue()
        ->and($capabilities->atLeast(17))->toBeFalse();
});

it('knows which extensions are installed', function (): void {
    $capabilities = new Capabilities(
        serverVersion: 170_005,
        extensions: ['plpgsql', 'pg_stat_statements'],
        settings: [],
        readsAllStatistics: false,
    );

    expect($capabilities->has('pg_stat_statements'))->toBeTrue()
        ->and($capabilities->has('pg_buffercache'))->toBeFalse();
});

it('reads whether a setting is turned on', function (): void {
    $capabilities = new Capabilities(
        serverVersion: 170_005,
        extensions: [],
        settings: ['track_counts' => 'on', 'track_io_timing' => 'off'],
        readsAllStatistics: false,
    );

    expect($capabilities->enabled('track_counts'))->toBeTrue()
        ->and($capabilities->enabled('track_io_timing'))->toBeFalse();
});

it('treats a setting it never asked about as off', function (): void {
    $capabilities = new Capabilities(
        serverVersion: 170_005,
        extensions: [],
        settings: [],
        readsAllStatistics: false,
    );

    expect($capabilities->enabled('track_io_timing'))->toBeFalse();
});
