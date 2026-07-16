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
    /**
     * PostgreSQL numbers transactions in 32 bits, half of which are the past and
     * half the future, so a table may fall this far behind the present before the
     * server refuses to accept another write.
     */
    public const int TRANSACTION_BUDGET = 2_147_483_647;

    /**
     * Multixact ids are numbered and compared the same way transaction ids are, so
     * the budget is the same size. It is a separate counter with a separate clock:
     * spending all of this one stops the cluster just as dead.
     */
    public const int MULTIXACT_BUDGET = 2_147_483_647;

    /**
     * @param  int  $xidAge  Transactions elapsed since this table's oldest row was
     *                       frozen. It falls to nearly zero when the table is
     *                       vacuumed and climbs with every transaction the cluster
     *                       runs, whether this table was touched or not.
     * @param  int  $mxidAge  Multixacts elapsed since this table's oldest row lock
     *                        was frozen. PostgreSQL allocates a multixact when more
     *                        than one transaction holds a row lock at once, so this
     *                        clock is driven by locking rather than by writing, and
     *                        it can run away on a table whose $xidAge is healthy.
     */
    public function __construct(
        public string $schema,
        public string $name,
        public int $liveTuples,
        public int $deadTuples,
        public int $modificationsSinceAnalyze,
        public int $xidAge,
        public int $mxidAge,
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
     * How much of the cluster's transaction budget this table has spent, as a
     * share. At 1.0 the database stops accepting writes.
     */
    public function transactionBudgetSpent(): float
    {
        return $this->xidAge / self::TRANSACTION_BUDGET;
    }

    /**
     * How much of the cluster's multixact budget this table has spent, as a share.
     * At 1.0 the database stops accepting writes — the same ending as the
     * transaction clock, reached by a different road.
     */
    public function multixactBudgetSpent(): float
    {
        return $this->mxidAge / self::MULTIXACT_BUDGET;
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
