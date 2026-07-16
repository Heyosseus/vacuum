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

            // Which tables are missing the cache, rather than the average that says
            // only that somebody is.
            //
            // Index blocks count as well as heap blocks. The ratio above is measured
            // over every block the database read, so a drill-down that weighed only
            // heap pages would answer a different question from the one it is under:
            // on an index-heavy workload the misses are largely index misses, and a
            // list built on heap_blks_read alone names the wrong tables. The reads
            // are coalesced because a table with no indexes reports null rather than
            // zero for them, and null would swallow the whole row.
            query: "SELECT relname,\n"
                ."       heap_blks_hit + coalesce(idx_blks_hit, 0) AS blks_hit,\n"
                ."       heap_blks_read + coalesce(idx_blks_read, 0) AS blks_read,\n"
                ."       round(100.0 * (heap_blks_hit + coalesce(idx_blks_hit, 0))\n"
                ."             / nullif(heap_blks_hit + coalesce(idx_blks_hit, 0)\n"
                ."                      + heap_blks_read + coalesce(idx_blks_read, 0), 0), 1) AS hit_percent\n"
                ."FROM pg_statio_user_tables\n"
                ."WHERE heap_blks_read + coalesce(idx_blks_read, 0) > 0\n"
                ."ORDER BY heap_blks_read + coalesce(idx_blks_read, 0) DESC\n"
                .'LIMIT 20;',
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
