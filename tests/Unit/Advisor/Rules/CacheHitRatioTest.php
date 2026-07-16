<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\CacheHitRatio;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\CacheStatistic;

// Not cache(): that is one of Laravel's own global helpers, and PHP will not have
// two functions of the same name in one process however differently they are used.
function blocks(int $hit, int $read): CacheStatistic
{
    return new CacheStatistic(blocksHit: $hit, blocksRead: $read, countingSince: null);
}

beforeEach(function (): void {
    config()->set('vacuum.thresholds.cache_hit_ratio', 0.99);
    config()->set('vacuum.thresholds.cache_hit_minimum_blocks', 100_000);
});

it('says nothing about a database serving almost everything from memory', function (): void {
    expect(app(CacheHitRatio::class)->inspect(blocks(hit: 999_000, read: 1_000)))->toBeNull();
});

it('refuses to judge a database nobody has read enough from', function (): void {
    // A database that has served a hundred blocks since it started can have any
    // ratio at all, and none of them mean anything.
    expect(app(CacheHitRatio::class)->inspect(blocks(hit: 50, read: 50)))->toBeNull();
});

it('reports a database going to disk more often than it should', function (): void {
    $finding = app(CacheHitRatio::class)->inspect(blocks(hit: 950_000, read: 50_000));

    expect($finding?->rule)->toBe('cache-hit-ratio')
        ->and($finding?->severity)->toBe(Severity::Warning)
        ->and($finding?->summary)->toContain('95.0%')
        ->and($finding?->summary)->toContain('99.0%');
});

it('raises its voice when the cache is missing one read in ten', function (): void {
    expect(app(CacheHitRatio::class)->inspect(blocks(hit: 850_000, read: 150_000))?->severity)
        ->toBe(Severity::Critical);
});

/**
 * The headline ratio comes from pg_stat_database, which counts every block the
 * database read — heap, index and TOAST alike. The drill-down that names the
 * tables responsible counted only heap blocks, so on an index-heavy workload it
 * pointed at whichever tables happened to scan their heaps and stayed silent
 * about the indexes actually doing the missing. The two numbers have to be
 * measuring the same thing or the list does not explain the score above it.
 */
it('counts index blocks alongside heap blocks when naming the tables responsible', function (): void {
    $finding = app(CacheHitRatio::class)->inspect(blocks(hit: 950_000, read: 50_000));

    expect($finding?->query)->toContain('idx_blks_hit')
        ->and($finding?->query)->toContain('idx_blks_read');
});

it('offers no statement to run, because there is not one', function (): void {
    // The fix is shared_buffers, and the right value depends on the machine. A
    // dashboard that hands you an ALTER SYSTEM with a number in it is guessing.
    $finding = app(CacheHitRatio::class)->inspect(blocks(hit: 950_000, read: 50_000));

    expect($finding?->remediation)->toBeNull()
        ->and($finding?->impact)->toContain('shared_buffers')
        ->and($finding?->impact)->toContain('operating system');
});
