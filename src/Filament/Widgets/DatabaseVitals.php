<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Heyosseus\Vacuum\Filament\Concerns\GatedWidget;
use Heyosseus\Vacuum\Filament\Models\Session as SessionModel;
use Heyosseus\Vacuum\Filament\Models\Table as TableModel;
use Heyosseus\Vacuum\Queries\CacheStatistics;
use Heyosseus\Vacuum\Support\Bytes;
use Illuminate\Support\Facades\Config;
use Override;

/**
 * The vitals a reader wants before any finding: how big the database is, how many tables
 * it holds, how much of its reading it served from memory, and how many connections are
 * open right now. None of these is a fault on its own; they are the room the findings
 * are about.
 */
final class DatabaseVitals extends StatsOverviewWidget
{
    use GatedWidget;

    protected static ?int $sort = 2;

    /**
     * @return array<Stat>
     */
    #[Override]
    protected function getStats(): array
    {
        return [
            Stat::make('Database size', Bytes::human($this->totalBytes()))
                ->description(number_format($this->tables()).' tables')
                ->color('gray'),

            Stat::make('Cache hit ratio', number_format($this->cacheHitRatio() * 100, 2).'%')
                ->description($this->cacheHitRatio() >= $this->cacheThreshold() ? 'Reading from memory' : 'Going to disk')
                ->color($this->cacheHitRatio() >= $this->cacheThreshold() ? 'success' : 'warning'),

            Stat::make('Sessions', (string) $this->sessions())
                ->description($this->activeSessions().' active')
                ->color($this->activeSessions() > 0 ? 'info' : 'gray'),
        ];
    }

    private function totalBytes(): int
    {
        // first() rather than value(): value() would try to read back an attribute named
        // after the whole raw expression, which is not a column and never resolves. The
        // aggregate is aliased and read by that alias instead.
        $row = TableModel::query()
            ->selectRaw('coalesce(sum(pg_total_relation_size(pg_stat_user_tables.relid)), 0) AS bytes')
            ->first();

        $bytes = $row?->getAttribute('bytes');

        return is_numeric($bytes) ? (int) $bytes : 0;
    }

    private function tables(): int
    {
        return TableModel::query()->count();
    }

    private function cacheHitRatio(): float
    {
        return app(CacheStatistics::class)->read()->hitRatio();
    }

    private function cacheThreshold(): float
    {
        $configured = Config::get('vacuum.thresholds.cache_hit_ratio', 0.99);

        return is_numeric($configured) ? (float) $configured : 0.99;
    }

    private function sessions(): int
    {
        return SessionModel::query()->count();
    }

    private function activeSessions(): int
    {
        return SessionModel::query()->whereRaw("pg_stat_activity.state = 'active'")->count();
    }
}
