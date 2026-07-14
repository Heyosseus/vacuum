<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Queries;

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Support\Cast;
use Heyosseus\Vacuum\Values\CacheStatistic;

/**
 * Reads how much of the database's reading PostgreSQL served out of memory.
 */
final readonly class CacheStatistics
{
    public function __construct(private ReadOnlyExecutor $executor) {}

    public function read(): CacheStatistic
    {
        $sql = <<<'SQL'
            SELECT blks_hit, blks_read, stats_reset
            FROM pg_stat_database
            WHERE datname = current_database()
            SQL;

        $row = $this->executor->select($sql)[0] ?? [];

        return new CacheStatistic(
            blocksHit: Cast::integer($row['blks_hit'] ?? null),
            blocksRead: Cast::integer($row['blks_read'] ?? null),
            countingSince: Cast::timestamp($row['stats_reset'] ?? null),
        );
    }
}
