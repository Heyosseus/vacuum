<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Inspections\CacheInspection;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Queries\CacheStatistics;
use Heyosseus\Vacuum\Values\Capabilities;

it('reads the cache statistics of the database it is pointed at', function (): void {
    $cache = app(CacheStatistics::class)->read();

    expect($cache->blocksHit)->toBeGreaterThan(0)
        ->and($cache->hitRatio())->toBeGreaterThan(0.0)
        ->and($cache->hitRatio())->toBeLessThanOrEqual(1.0);
});

it('puts the statistics it read to the rules', function (): void {
    // No test database misses enough of its cache to trip a real threshold, so
    // the target is set where nothing could ever meet it.
    config()->set('vacuum.thresholds.cache_hit_ratio', 1.0);
    config()->set('vacuum.thresholds.cache_hit_minimum_blocks', 1);

    expect(array_column(app(CacheInspection::class)->findings(), 'rule'))->toContain('cache-hit-ratio');
});

it('explains itself when postgres is not counting rather than reporting a perfect cache', function (): void {
    // With track_counts off every counter reads zero, and a hit ratio computed
    // from zeroes is a flawless one. Saying nothing would be a lie of omission.
    app()->instance(Capabilities::class, new Capabilities(
        serverVersion: 170_005,
        extensions: [],
        settings: ['track_counts' => 'off'],
        readsAllStatistics: true,
    ));

    $findings = app(CacheInspection::class)->findings();

    expect(array_column($findings, 'rule'))->toBe(['statistics-disabled'])
        ->and($findings[0]->remediation)->toBe('ALTER SYSTEM SET track_counts = on;');
});

it('reports an unmeasurable cache as unknown rather than as merely informational', function (): void {
    $findings = (new CacheInspection(
        new Capabilities(
            serverVersion: 170005,
            extensions: [],
            settings: ['track_counts' => 'off'],
            readsAllStatistics: true,
        ),
        app(CacheStatistics::class),
        [],
    ))->findings();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('statistics-disabled')
        ->and($findings[0]->severity)->toBe(Severity::Unknown);
});
