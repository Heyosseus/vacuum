<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Heyosseus\Vacuum\Filament\Concerns\GatedWidget;
use Heyosseus\Vacuum\Queries\IndexStatistics;
use Heyosseus\Vacuum\Values\IndexStatistic;
use Override;

/**
 * How much of the space spent on indexes is buying anything. Every index is maintained
 * on every write whether or not a query ever reads it, so the share that nothing has
 * read is close to pure cost -- and seeing it as a slice of the whole is what turns "one
 * unused index" into "a third of the index space does nothing".
 */
final class IndexFootprint extends ChartWidget
{
    use GatedWidget;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Index space (MB)';

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
        $indexes = app(IndexStatistics::class)->all();

        $read = $this->megabytes(array_filter($indexes, static fn (IndexStatistic $index): bool => ! $index->neverUsed()));
        $unused = $this->megabytes(array_filter($indexes, static fn (IndexStatistic $index): bool => $index->neverUsed()));

        return [
            'datasets' => [
                [
                    'data' => [$read, $unused],
                    'backgroundColor' => ['#10b981', '#ef4444'],
                ],
            ],
            'labels' => ['Read', 'Never read'],
        ];
    }

    /**
     * @param  array<int, IndexStatistic>  $indexes
     */
    private function megabytes(array $indexes): float
    {
        $bytes = array_sum(array_map(static fn (IndexStatistic $index): int => $index->bytes, $indexes));

        return round($bytes / 1024 / 1024, 1);
    }
}
