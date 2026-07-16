<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

use Carbon\CarbonImmutable;

/**
 * One table, and everything PostgreSQL will say about it.
 *
 * The ratios are the point. A count of sequential scans means nothing on its own —
 * a small table is supposed to be scanned — but the share of reads that were scans,
 * next to the size of the table, is a sentence. The same goes for HOT updates: the
 * number is noise, and the proportion is a diagnosis.
 */
final readonly class TableProfile
{
    /**
     * @param  int  $hotUpdates  Updates PostgreSQL fitted into the same page, which cost no
     *                           index maintenance at all. The rest rewrote every index on
     *                           the table.
     * @param  float  $vacuumScaleFactor  The effective one: the table's own reloption if it has
     *                                    set one, otherwise the server's.
     * @param  bool  $tuned  Whether this table overrides any of the server's autovacuum
     *                       settings for itself.
     */
    public function __construct(
        public string $schema,
        public string $name,
        public int $liveTuples,
        public int $deadTuples,
        public int $modificationsSinceAnalyze,
        public int $xidAge,
        public int $mxidAge,
        public int $heapBytes,
        public int $indexBytes,
        public int $toastBytes,
        public int $totalBytes,
        public int $sequentialScans,
        public int $sequentialTuplesRead,
        public int $indexScans,
        public int $indexTuplesFetched,
        public int $inserts,
        public int $updates,
        public int $hotUpdates,
        public int $deletes,
        public ?CarbonImmutable $lastVacuum,
        public ?CarbonImmutable $lastAutovacuum,
        public ?CarbonImmutable $lastAnalyze,
        public ?CarbonImmutable $lastAutoanalyze,
        public float $vacuumScaleFactor,
        public int $vacuumThreshold,
        public float $analyzeScaleFactor,
        public int $analyzeThreshold,
        public bool $tuned,
    ) {}

    public function qualifiedName(): string
    {
        return $this->schema.'.'.$this->name;
    }

    public function deadTupleRatio(): float
    {
        $total = $this->liveTuples + $this->deadTuples;

        return $total === 0 ? 0.0 : $this->deadTuples / $total;
    }

    /**
     * The share of reads that scanned the whole table rather than looking a row up.
     *
     * Null when nothing has read the table at all, which is a different fact from
     * "no scans", and the page should say the different thing.
     */
    public function sequentialShare(): ?float
    {
        $reads = $this->sequentialScans + $this->indexScans;

        return $reads === 0 ? null : $this->sequentialScans / $reads;
    }

    /**
     * The share of updates PostgreSQL managed to keep inside one page.
     *
     * A HOT update writes no index entries: the new row version lives in the same
     * page as the old one, chained to it, and every index still points at the page.
     * A low ratio on an update-heavy table means every update is rewriting every
     * index, and lowering the fillfactor is what buys the room to avoid it.
     */
    public function hotUpdateRatio(): ?float
    {
        return $this->updates === 0 ? null : $this->hotUpdates / $this->updates;
    }

    /**
     * The number of dead rows at which autovacuum will actually start on this table.
     *
     * This is the question everybody has and almost nobody can answer, because the
     * setting is a scale factor rather than a number: at the default of 0.2, a table
     * of fifty million rows is allowed ten million dead ones before anything moves.
     */
    public function vacuumsAt(): int
    {
        return $this->vacuumThreshold + (int) ($this->vacuumScaleFactor * $this->liveTuples);
    }

    public function analyzesAt(): int
    {
        return $this->analyzeThreshold + (int) ($this->analyzeScaleFactor * $this->liveTuples);
    }

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
