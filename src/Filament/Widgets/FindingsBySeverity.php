<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Filament\Concerns\GatedWidget;
use Heyosseus\Vacuum\Filament\Support\PanelData;
use Override;

/**
 * The shape of the findings at a glance: how many are costing you now, how many will,
 * and how many are merely worth knowing. The colours are the advisor's own -- red, amber
 * and a neutral gray -- so the chart reads the same way the list beneath it does.
 */
final class FindingsBySeverity extends ChartWidget
{
    use GatedWidget;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Findings by severity';

    protected int|string|array $columnSpan = 1;

    #[Override]
    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function getData(): array
    {
        $findings = app(PanelData::class)->findings();

        return [
            'datasets' => [
                [
                    'data' => [
                        $this->count($findings, Severity::Critical),
                        $this->count($findings, Severity::Warning),
                        $this->count($findings, Severity::Info),
                    ],
                    'backgroundColor' => ['#ef4444', '#f59e0b', '#9ca3af'],
                ],
            ],
            'labels' => ['Critical', 'Warning', 'Info'],
        ];
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function count(array $findings, Severity $severity): int
    {
        return count(array_filter($findings, static fn (Finding $finding): bool => $finding->severity === $severity));
    }
}
