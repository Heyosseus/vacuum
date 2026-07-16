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

it('finds a library in the shared_preload_libraries list however it is written', function (): void {
    // The GUC is a comma-separated list, and entries come back padded or quoted
    // depending on how the administrator wrote them.
    $capabilities = new Capabilities(
        serverVersion: 170_005,
        extensions: [],
        settings: ['shared_preload_libraries' => 'auto_explain, "pg_stat_statements"'],
        readsAllStatistics: false,
    );

    expect($capabilities->preloaded('pg_stat_statements'))->toBeTrue()
        ->and($capabilities->preloaded('auto_explain'))->toBeTrue()
        ->and($capabilities->preloaded('pg_buffercache'))->toBeFalse();
});

it('does not mistake a longer library name for the one asked about', function (): void {
    $capabilities = new Capabilities(
        serverVersion: 170_005,
        extensions: [],
        settings: ['shared_preload_libraries' => 'pg_stat_statements_plus'],
        readsAllStatistics: false,
    );

    expect($capabilities->preloaded('pg_stat_statements'))->toBeFalse();
});

it('treats a preload list nobody probed as empty', function (): void {
    $capabilities = new Capabilities(
        serverVersion: 170_005,
        extensions: [],
        settings: [],
        readsAllStatistics: false,
    );

    expect($capabilities->preloaded('pg_stat_statements'))->toBeFalse();
});

it('trusts pg_stat_statements only when it is created and preloaded both', function (): void {
    $tracking = static fn (array $extensions, array $settings): bool => (new Capabilities(
        serverVersion: 170_005,
        extensions: $extensions,
        settings: $settings,
        readsAllStatistics: false,
    ))->tracksStatements();

    $preloaded = ['shared_preload_libraries' => 'pg_stat_statements'];

    expect($tracking(['pg_stat_statements'], $preloaded))->toBeTrue()
        // CREATE EXTENSION succeeds without the preload, and the view then
        // throws on the first read: the half-installed state this guards.
        ->and($tracking(['pg_stat_statements'], ['shared_preload_libraries' => 'auto_explain']))->toBeFalse()
        ->and($tracking([], $preloaded))->toBeFalse()
        ->and($tracking([], []))->toBeFalse();
});

it('gives pg_stat_statements the benefit of the doubt when the preload list is hidden', function (): void {
    // The server shows shared_preload_libraries only to pg_read_all_settings,
    // so a probe without the key proves nothing either way. Assuming off would
    // blind the panel for the modest roles most applications connect with.
    $capabilities = new Capabilities(
        serverVersion: 170_005,
        extensions: ['pg_stat_statements'],
        settings: [],
        readsAllStatistics: false,
    );

    expect($capabilities->tracksStatements())->toBeTrue();
});
