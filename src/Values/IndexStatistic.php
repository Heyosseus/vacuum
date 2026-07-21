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
     * @param  bool  $constraintOwned  Whether a constraint depends on this index. Not the
     *                                 same question as $unique: an exclusion constraint
     *                                 is backed by an index that is neither unique nor
     *                                 primary, and PostgreSQL still refuses to drop it.
     * @param  bool  $replicaIdentity  Whether logical replication identifies rows of this
     *                                 table by this index.
     * @param  bool  $partitionChild  Whether this index is attached to a partitioned
     *                                index, which can only be dropped through its parent.
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
        public bool $constraintOwned,
        public bool $replicaIdentity,
        public bool $partitionChild,
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
     *
     * Also true of every index that cannot simply be dropped. An exclusion
     * constraint is backed by an index that is neither unique nor primary; the
     * replica identity is how logical replication finds a row to update; a
     * partition child belongs to its parent index. PostgreSQL refuses DROP INDEX
     * on all three, so recommending it produces an error in the reader's hands --
     * and for the exclusion case, the index really is enforcing something.
     */
    public function constrains(): bool
    {
        return $this->primary
            || $this->unique
            || $this->constraintOwned
            || $this->replicaIdentity
            || $this->partitionChild;
    }
}
