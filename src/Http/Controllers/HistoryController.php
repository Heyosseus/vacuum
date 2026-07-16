<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Http\Controllers;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Database\ConnectionResolver;
use Heyosseus\Vacuum\History\FindingPresenter;
use Heyosseus\Vacuum\History\FindingView;
use Heyosseus\Vacuum\History\History;
use Heyosseus\Vacuum\Support\Sparkline;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as Views;

/**
 * The standalone Blade history page: the health line over time, what is newly
 * wrong, what has cleared, and what is forecast to break.
 *
 * The route it answers is registered only where history is on, so this is reached
 * only when there is something to show; the empty state still covers the moment
 * before the first snapshot has run.
 */
final readonly class HistoryController
{
    public function __construct(
        private History $history,
        private FindingPresenter $presenter,
        private Advisor $advisor,
        private Capabilities $capabilities,
        private ConnectionResolver $connections,
    ) {}

    public function __invoke(): View
    {
        $scores = $this->history->scores();

        $forecasts = array_values(array_filter(
            $this->presenter->present($this->advisor->findings()),
            static fn (FindingView $view): bool => $view->forecast instanceof \Heyosseus\Vacuum\History\Forecast,
        ));

        return Views::make('vacuum::history', [
            'scores' => $scores,
            'sparkline' => Sparkline::points(array_map(
                static fn (array $point): int => $point['score'],
                $scores,
            )),
            'latestScore' => $scores === [] ? null : $scores[count($scores) - 1]['score'],
            'newFindings' => $this->history->newFindings(),
            'clearedFindings' => $this->history->clearedFindings(),
            'forecasts' => $forecasts,
            'hasHistory' => $this->history->latest() instanceof \Heyosseus\Vacuum\History\Models\Snapshot,
            'capabilities' => $this->capabilities,
            'connection' => $this->connections->resolve()->getName() ?? 'unnamed',
        ]);
    }
}
