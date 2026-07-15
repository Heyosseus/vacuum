<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Heyosseus\Vacuum\Filament\Models\Table as TableModel;
use Illuminate\Database\Query\Expression;
use Override;

/**
 * Where the disk is going: the handful of tables that account for most of the database's
 * size, in megabytes, tallest first. It is the first question anyone asks of a database
 * that has grown, and the one the standalone list makes you sort for.
 */
final class LargestTables extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Largest tables (MB)';

    protected int|string|array $columnSpan = 1;

    #[Override]
    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function getData(): array
    {
        $tables = TableModel::query()
            ->select(['pg_stat_user_tables.relname'])
            ->addSelect(new Expression('pg_total_relation_size(pg_stat_user_tables.relid) AS total_bytes'))
            ->orderByDesc('total_bytes')
            ->limit(8)
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Size (MB)',
                    'data' => $tables->map(static fn (TableModel $table): float => round(($table->total_bytes ?? 0) / 1024 / 1024, 1))->all(),
                    'backgroundColor' => '#6366f1',
                ],
            ],
            'labels' => $tables->map(static fn (TableModel $table): string => $table->relname)->all(),
        ];
    }
}
