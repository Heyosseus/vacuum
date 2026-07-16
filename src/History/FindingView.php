<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History;

use Heyosseus\Vacuum\Advisor\Finding;

/**
 * A finding with what history knows about it laid over the top: which way its number
 * is moving, when it is projected to become critical, and — for the two findings
 * whose number is a lifetime average — the honest figure measured over the last
 * interval instead.
 *
 * The finding underneath is untouched. History is read here, at the point of
 * display, so the advisor stays a pure point-in-time reading and the CI gate keeps
 * failing on exactly what it failed on before.
 */
final readonly class FindingView
{
    public function __construct(
        public Finding $finding,
        public Trend $trend,
        public ?Forecast $forecast,
        public ?string $intervalSummary,
    ) {}

    /**
     * The sentence to show: the interval-accurate one where history could compute it,
     * and the finding's own otherwise.
     */
    public function summary(): string
    {
        return $this->intervalSummary ?? $this->finding->summary;
    }

    public function isRising(): bool
    {
        return $this->trend === Trend::Rising;
    }

    public function isFalling(): bool
    {
        return $this->trend === Trend::Falling;
    }
}
