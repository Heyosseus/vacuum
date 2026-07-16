<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Support;

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\History\FindingPresenter;
use Heyosseus\Vacuum\History\FindingView;
use Heyosseus\Vacuum\History\History;
use Heyosseus\Vacuum\History\Models\SnapshotFinding;
use Heyosseus\Vacuum\Support\Sparkline;

/**
 * Everything the History page shows, assembled once per request.
 *
 * The page's parts — the health line, what is newly wrong, what has cleared, and
 * what is forecast to break — each read history for themselves if left alone. Bound
 * once, this reads it once and hands them all the same answers, the same way the
 * Overview's PanelData does for the advisor.
 */
final class HistoryPanel
{
    /** @var list<FindingView>|null */
    private ?array $forecasts = null;

    public function __construct(
        private readonly History $history,
        private readonly FindingPresenter $presenter,
        private readonly Advisor $advisor,
    ) {}

    public function enabled(): bool
    {
        return $this->history->enabled();
    }

    /** Whether a single snapshot has been recorded yet — nothing to draw until one has. */
    public function hasHistory(): bool
    {
        return $this->history->latest() instanceof \Heyosseus\Vacuum\History\Models\Snapshot;
    }

    /**
     * @return list<array{at: CarbonImmutable, score: int}>
     */
    public function scores(): array
    {
        return $this->history->scores();
    }

    /**
     * The health line as SVG polyline points, or an empty string when there is
     * nothing yet to draw.
     */
    public function healthSparkline(): string
    {
        return Sparkline::points(array_map(
            static fn (array $point): int => $point['score'],
            $this->scores(),
        ));
    }

    public function latestScore(): ?int
    {
        $scores = $this->scores();

        return $scores === [] ? null : $scores[count($scores) - 1]['score'];
    }

    /**
     * @return list<SnapshotFinding>
     */
    public function newFindings(): array
    {
        return $this->history->newFindings();
    }

    /**
     * @return list<SnapshotFinding>
     */
    public function clearedFindings(): array
    {
        return $this->history->clearedFindings();
    }

    /**
     * The findings that carry a forecast: the ones projected to cross critical.
     *
     * @return list<FindingView>
     */
    public function forecasts(): array
    {
        return $this->forecasts ??= array_values(array_filter(
            $this->presenter->present($this->advisor->findings()),
            static fn (FindingView $view): bool => $view->forecast instanceof \Heyosseus\Vacuum\History\Forecast,
        ));
    }
}
