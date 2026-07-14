<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

use Carbon\CarbonImmutable;

/**
 * One row of pg_stat_user_tables: what PostgreSQL knows about how a table has
 * been written to, and when it was last cleaned up.
 */
final readonly class TableStatistic
{
    public function __construct(
        public string $schema,
        public string $name,
        public int $liveTuples,
        public int $deadTuples,
        public int $modificationsSinceAnalyze,
        public ?CarbonImmutable $lastVacuum,
        public ?CarbonImmutable $lastAutovacuum,
        public ?CarbonImmutable $lastAnalyze,
        public ?CarbonImmutable $lastAutoanalyze,
    ) {}

    public function qualifiedName(): string
    {
        return "{$this->schema}.{$this->name}";
    }

    /**
     * The share of the table's tuples that are dead. This is the number the
     * bloat panel is built on: dead tuples are rows that still occupy pages,
     * still get read, and are only reclaimed by a vacuum.
     */
    public function deadTupleRatio(): float
    {
        $total = $this->liveTuples + $this->deadTuples;

        if ($total === 0) {
            return 0.0;
        }

        return $this->deadTuples / $total;
    }

    /**
     * A table is vacuumed either by hand or by autovacuum; only the more recent
     * of the two says anything about how stale it is.
     */
    public function lastVacuumedAt(): ?CarbonImmutable
    {
        return $this->latest($this->lastVacuum, $this->lastAutovacuum);
    }

    public function lastAnalyzedAt(): ?CarbonImmutable
    {
        return $this->latest($this->lastAnalyze, $this->lastAutoanalyze);
    }

    private function latest(?CarbonImmutable $manual, ?CarbonImmutable $automatic): ?CarbonImmutable
    {
        if (! $manual instanceof CarbonImmutable) {
            return $automatic;
        }

        if (! $automatic instanceof CarbonImmutable) {
            return $manual;
        }

        return $manual->greaterThan($automatic) ? $manual : $automatic;
    }
}
