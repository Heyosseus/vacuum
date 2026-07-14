<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Values;

use Carbon\CarbonImmutable;

/**
 * How often the database found a block in memory rather than reading it.
 *
 * Cumulative since PostgreSQL last reset its counters, which makes this a lifetime
 * average and not a measure of how the database is behaving this afternoon.
 */
final readonly class CacheStatistic
{
    public function __construct(
        public int $blocksHit,
        public int $blocksRead,
        public ?CarbonImmutable $countingSince,
    ) {}

    public function blocksRequested(): int
    {
        return $this->blocksHit + $this->blocksRead;
    }

    public function hitRatio(): float
    {
        $requested = $this->blocksRequested();

        // Nothing was asked for, so nothing was missed.
        if ($requested === 0) {
            return 1.0;
        }

        return $this->blocksHit / $requested;
    }
}
