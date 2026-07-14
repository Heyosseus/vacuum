<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\CacheRule;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\CacheStatistic;
use Illuminate\Contracts\Config\Repository;

/**
 * Finds a database that is going to disk more often than it should.
 */
final readonly class CacheHitRatio implements CacheRule
{
    /** Missing one read in ten is not a tuning problem any more. */
    private const float CRITICAL_RATIO = 0.90;

    public function __construct(private Repository $config) {}

    public function inspect(CacheStatistic $cache): ?Finding
    {
        // A database that has served a hundred blocks can have any ratio at all,
        // and none of them mean anything.
        if ($cache->blocksRequested() < $this->minimumBlocks()) {
            return null;
        }

        $ratio = $cache->hitRatio();
        $threshold = $this->threshold();

        if ($ratio >= $threshold) {
            return null;
        }

        $measured = number_format($ratio * 100, 1).'%';
        $target = number_format($threshold * 100, 1).'%';

        return new Finding(
            rule: 'cache-hit-ratio',
            subject: 'database',
            severity: $ratio < self::CRITICAL_RATIO ? Severity::Critical : Severity::Warning,
            summary: "{$measured} of block reads were served from memory, against a target of {$target}.",
            impact: 'Reads that miss the cache go to disk, and a query that reads from disk is a query that '
                .'waits. The usual cause is shared_buffers being too small for the working set. Read the '
                .'number carefully, though: it counts only PostgreSQL\'s own cache, and the operating system '
                .'holds a cache of its own underneath it, so a miss here is not always a trip to the disk. It '
                .'is also an average over the whole life of these counters, which hides an afternoon of '
                .'thrashing inside a year of good behaviour.',
        );
    }

    private function threshold(): float
    {
        $threshold = $this->config->get('vacuum.thresholds.cache_hit_ratio', 0.99);

        return is_numeric($threshold) ? (float) $threshold : 0.99;
    }

    private function minimumBlocks(): int
    {
        $minimum = $this->config->get('vacuum.thresholds.cache_hit_minimum_blocks', 100_000);

        return is_numeric($minimum) ? (int) $minimum : 100_000;
    }
}
