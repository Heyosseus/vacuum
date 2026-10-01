<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Filament\Concerns\GatedWidget;
use Heyosseus\Vacuum\Support\IgnoredSchemas;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\QueryException;
use Override;

/**
 * Where the disk is going: the handful of tables that account for most of the database's
 * size, in megabytes, tallest first. It is the first question anyone asks of a database
 * that has grown, and the one the standalone list makes you sort for.
 */
final class LargestTables extends ChartWidget
{
    use GatedWidget;

    /** SQLSTATE query_canceled, which is what statement_timeout raises. */
    private const string QUERY_CANCELED = '57014';

    protected static ?int $sort = 4;

    protected ?string $heading = 'Largest tables (MB)';

    protected int|string|array $columnSpan = 1;

    /**
     * No polling. Filament's default re-renders the chart every five seconds, and every
     * render sizes every relation in the database -- pg_total_relation_size() across
     * pg_stat_user_tables, which is seconds on a large database and far longer under
     * load. Where the disk has gone does not change between two polls; reloading the
     * page redraws it.
     */
    protected ?string $pollingInterval = null;

    #[Override]
    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * The description is rendered before the chart's data is read, so the data is read
     * first: a sizing that ran out of time says so under the heading rather than
     * leaving an empty chart to be mistaken for an empty database.
     */
    #[Override]
    public function getDescription(): string|Htmlable|null
    {
        $this->getCachedData();

        return parent::getDescription();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function getData(): array
    {
        $tables = $this->largest();

        return [
            'datasets' => [
                [
                    'label' => 'Size (MB)',
                    'data' => array_map(static fn (array $table): float => round($table['bytes'] / 1024 / 1024, 1), $tables),
                    'backgroundColor' => '#6366f1',
                ],
            ],
            'labels' => array_map(static fn (array $table): string => $table['relname'], $tables),
        ];
    }

    /**
     * The eight largest tables, through the read-only executor.
     *
     * This sizes every relation in the database, which is seconds on a large one and
     * far longer under load. Through Eloquent nothing ever stopped it; here the
     * package's statement timeout does, and a database too large to size inside it
     * gets a chart that says so instead of a request that holds a worker for a minute.
     *
     * @return list<array{relname: string, bytes: int}>
     */
    private function largest(): array
    {
        try {
            $rows = app(ReadOnlyExecutor::class)->select(<<<'SQL'
                SELECT relname, pg_total_relation_size(relid) AS bytes
                FROM pg_stat_user_tables
                WHERE schemaname <> ALL (string_to_array(?, ','))
                ORDER BY bytes DESC
                LIMIT 8
                SQL, [implode(',', app(IgnoredSchemas::class)->all())]);
        } catch (QueryException $exception) {
            // Only the timeout is expected; anything else is a real fault and is left to
            // surface as one.
            if (($exception->errorInfo[0] ?? null) !== self::QUERY_CANCELED) {
                throw $exception;
            }

            $this->description = 'Sizing every table took longer than the statement timeout allows.';

            return [];
        }

        $tables = [];

        foreach ($rows as $row) {
            $tables[] = [
                'relname' => is_string($row['relname'] ?? null) ? $row['relname'] : '',
                'bytes' => is_numeric($row['bytes'] ?? null) ? (int) $row['bytes'] : 0,
            ];
        }

        return $tables;
    }
}
