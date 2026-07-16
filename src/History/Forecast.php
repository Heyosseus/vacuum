<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History;

use Carbon\CarbonImmutable;

/**
 * When a climbing number is projected to cross the line that makes it critical.
 *
 * The value of history stated as a date: not that a table is bloated, but that at
 * the rate it has been filling it will be a problem a week on Tuesday, which is the
 * difference between something to schedule and something to panic about.
 */
final readonly class Forecast
{
    public function __construct(
        public CarbonImmutable $reachesAt,
        public int $days,
        public float $perDay,
    ) {}
}
