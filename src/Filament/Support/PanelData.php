<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Support;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Health;

/**
 * One inspection, shared across the Overview's widgets.
 *
 * The health score, the findings list and the severity chart are three Livewire
 * components that would each, left alone, run the whole advisor for themselves -- eight
 * inspections and their queries, three times over, for one page load. Bound once per
 * request, this runs them once and hands every widget the same findings, so the numbers
 * on the page cannot disagree and the database is asked its questions a single time.
 */
final class PanelData
{
    /** @var list<Finding>|null */
    private ?array $findings = null;

    private ?Health $health = null;

    public function __construct(private readonly Advisor $advisor) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        return $this->findings ??= $this->advisor->findings();
    }

    public function health(): Health
    {
        return $this->health ??= Health::from($this->findings());
    }
}
