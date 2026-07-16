<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Support;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Health;
use Heyosseus\Vacuum\History\FindingPresenter;
use Heyosseus\Vacuum\History\FindingView;

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

    /** @var list<FindingView>|null */
    private ?array $findingViews = null;

    private ?Health $health = null;

    public function __construct(
        private readonly Advisor $advisor,
        private readonly FindingPresenter $presenter,
    ) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        return $this->findings ??= $this->advisor->findings();
    }

    /**
     * The same findings with history laid over them: each with its trend, its
     * forecast, and the interval figure where there is one. The presenter passes
     * them through untouched when history is off, so this is always safe to render.
     *
     * @return list<FindingView>
     */
    public function findingViews(): array
    {
        return $this->findingViews ??= $this->presenter->present($this->findings());
    }

    public function health(): Health
    {
        return $this->health ??= Health::from($this->findings());
    }
}
