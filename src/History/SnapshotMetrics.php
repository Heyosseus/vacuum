<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History;

use Heyosseus\Vacuum\Queries\BloatEstimates;
use Heyosseus\Vacuum\Queries\CacheStatistics;
use Heyosseus\Vacuum\Queries\Statements;
use Heyosseus\Vacuum\Queries\TableStatistics;
use Illuminate\Contracts\Config\Repository;

/**
 * Gathers the raw per-object numbers a snapshot stores, so that a later reading
 * can say which way each one is moving.
 *
 * It reads the same queries the advisor does, through the same read-only path, and
 * writes nothing itself. What it does decide is what is worth keeping: trending
 * every table on a database with thousands of them would fill the metrics table
 * with tables nobody will ever ask about. So a table earns a row by being large
 * enough for its space to matter, or by having a freeze age high enough that
 * wraparound might one day be about it — the one metric no size floor may hide.
 */
final readonly class SnapshotMetrics
{
    public function __construct(
        private TableStatistics $tables,
        private BloatEstimates $bloat,
        private CacheStatistics $cache,
        private Statements $statements,
        private Repository $config,
    ) {}

    /**
     * @return list<CollectedMetric>
     */
    public function collect(): array
    {
        return [
            ...$this->cacheMetrics(),
            ...$this->statementMetrics(),
            ...$this->tableMetrics(),
        ];
    }

    /**
     * @return list<CollectedMetric>
     */
    private function cacheMetrics(): array
    {
        $cache = $this->cache->read();

        return [new CollectedMetric(
            MetricKind::DbCache,
            'database',
            (float) $cache->blocksHit,
            (float) $cache->blocksRead,
        )];
    }

    /**
     * @return list<CollectedMetric>
     */
    private function statementMetrics(): array
    {
        $metrics = [];

        foreach ($this->statements->slowest() as $statement) {
            $metrics[] = new CollectedMetric(
                MetricKind::Statement,
                $statement->queryId,
                $statement->totalMilliseconds,
                (float) $statement->calls,
            );
        }

        return $metrics;
    }

    /**
     * @return list<CollectedMetric>
     */
    private function tableMetrics(): array
    {
        $floor = $this->tableFloor();
        $watch = $this->xidWatch();

        // Real size per table, from the one query that knows it, so freeze age and
        // dead tuples can be gated on whether the table is large enough to matter.
        $realBytes = [];

        foreach ($this->bloat->all() as $estimate) {
            $realBytes[$estimate->qualifiedName()] = $estimate->realBytes;
        }

        $metrics = [];

        foreach ($this->bloat->all() as $estimate) {
            if ($estimate->realBytes >= $floor) {
                $metrics[] = new CollectedMetric(
                    MetricKind::TableBloatBytes,
                    $estimate->qualifiedName(),
                    (float) $estimate->bloatBytes,
                    (float) $estimate->realBytes,
                );
            }
        }

        foreach ($this->tables->all() as $table) {
            $name = $table->qualifiedName();
            $large = ($realBytes[$name] ?? 0) >= $floor;

            // Freeze age is kept for a large table, or for any table already high
            // enough that a wraparound forecast could one day be drawn through it.
            if ($large || $table->xidAge >= $watch) {
                $metrics[] = new CollectedMetric(
                    MetricKind::TableXidAge,
                    $name,
                    (float) $table->xidAge,
                );
            }

            if ($large) {
                $metrics[] = new CollectedMetric(
                    MetricKind::TableDeadTuples,
                    $name,
                    (float) $table->deadTuples,
                    (float) $table->liveTuples,
                );
            }
        }

        return $metrics;
    }

    private function tableFloor(): int
    {
        $floor = $this->config->get('vacuum.history.metric_table_min_bytes', 10 * 1024 * 1024);

        return is_numeric($floor) ? (int) $floor : 10 * 1024 * 1024;
    }

    /**
     * The freeze age at which a table's xid metric is kept regardless of its size:
     * half the age the wraparound rule warns at, so a forecast has room to run
     * before the warning it is trying to predict.
     */
    private function xidWatch(): int
    {
        $warning = $this->config->get('vacuum.thresholds.wraparound_xid_age', 200_000_000);
        $warning = is_numeric($warning) ? (int) $warning : 200_000_000;

        return intdiv($warning, 2);
    }
}
