<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Filament\Concerns\GatedWidget;
use Heyosseus\Vacuum\Filament\Support\GradeColor;
use Heyosseus\Vacuum\Filament\Support\PanelData;
use Override;

/**
 * The one number for the database, worked out from the findings and nothing else, sat
 * beside the count of what produced it. The score cannot say anything the list below it
 * does not: a green 92 above three critical problems is the way these dashboards usually
 * lie, and this one is a hundred minus what the findings cost.
 */
final class HealthScore extends StatsOverviewWidget
{
    use GatedWidget;

    protected static ?int $sort = 1;

    /**
     * @return array<Stat>
     */
    #[Override]
    protected function getStats(): array
    {
        $data = app(PanelData::class);
        $health = $data->health();
        $findings = $data->findings();

        $critical = count(array_filter($findings, static fn (Finding $finding): bool => $finding->severity === Severity::Critical));
        $warnings = count(array_filter($findings, static fn (Finding $finding): bool => $finding->severity === Severity::Warning));

        return [
            Stat::make('Health', $health->score.' / 100')
                ->description($health->capped ? 'Capped at '.$health->grade->value.' by a critical finding' : 'Grade '.$health->grade->value)
                ->descriptionIcon($health->capped ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color(GradeColor::for($health->grade)),

            Stat::make('Critical', (string) $critical)
                ->description($critical === 0 ? 'Nothing costing you now' : 'Costing you now')
                ->color($critical === 0 ? 'success' : 'danger'),

            Stat::make('Warnings', (string) $warnings)
                ->description($warnings === 0 ? 'Nothing to fix' : 'Fix when convenient')
                ->color($warnings === 0 ? 'success' : 'warning'),
        ];
    }
}
