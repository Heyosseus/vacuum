<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

use Carbon\CarbonImmutable;

/**
 * An index, how much space it takes, and how often anything has read it.
 */
final readonly class IndexStatistic
{
    /**
     * @param  int  $scans  Reads served since PostgreSQL last reset its counters.
     * @param  bool  $valid  Whether the planner will consider it at all. A failed
     *                       CREATE INDEX CONCURRENTLY leaves an index behind that
     *                       every write maintains and no query is allowed to use.
     * @param  CarbonImmutable|null  $countingSince  When that reset was, if it ever happened.
     */
    public function __construct(
        public string $schema,
        public string $table,
        public string $name,
        public int $scans,
        public int $bytes,
        public bool $unique,
        public bool $primary,
        public bool $valid,
        public ?CarbonImmutable $countingSince,
    ) {}

    public function qualifiedName(): string
    {
        return $this->schema.'.'.$this->name;
    }

    public function neverUsed(): bool
    {
        return $this->scans === 0;
    }

    /**
     * Whether the index is a rule the database enforces rather than a shortcut it
     * offers. A unique or primary index may be read by nothing at all and still be
     * doing its job on every single write.
     */
    public function constrains(): bool
    {
        return $this->primary || $this->unique;
    }
}
