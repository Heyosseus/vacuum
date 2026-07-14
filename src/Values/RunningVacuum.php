<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

use Carbon\CarbonImmutable;

/**
 * A vacuum PostgreSQL is running at this moment.
 *
 * Not a finding. Nothing is wrong with a vacuum: this is the database keeping the
 * promise the rest of the dashboard is asking it to keep.
 */
final readonly class RunningVacuum
{
    /**
     * @param  string  $phase  PostgreSQL's own words: "scanning heap", "vacuuming indexes", and so on.
     * @param  int  $blocksTotal  Zero in the phases where PostgreSQL is not counting blocks.
     * @param  int  $indexPasses  How many times it has been round the indexes. More than one
     *                            means maintenance_work_mem was too small to hold the dead
     *                            tuples in one pass, and the indexes are being read again.
     * @param  bool  $automatic  Whether autovacuum started it, or a person did.
     */
    public function __construct(
        public int $pid,
        public string $schema,
        public string $table,
        public string $phase,
        public int $blocksTotal,
        public int $blocksScanned,
        public int $indexPasses,
        public ?CarbonImmutable $startedAt,
        public bool $automatic,
    ) {}

    public function qualifiedName(): string
    {
        return $this->schema.'.'.$this->table;
    }

    /**
     * How far through the heap it is, or null when PostgreSQL is in a phase that
     * does not count blocks. A progress bar that invents a number for a phase with
     * no denominator is a progress bar that lies.
     */
    public function percentScanned(): ?float
    {
        if ($this->blocksTotal === 0) {
            return null;
        }

        return round($this->blocksScanned / $this->blocksTotal * 100, 1);
    }
}
