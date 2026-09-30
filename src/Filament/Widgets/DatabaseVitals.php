<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
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
        // Each figure is read once per render. The cache hit ratio used to be asked for
        // three times -- the value, its description, its colour -- and the active sessions
        // twice, each ask a query of its own, on a card that polls.
        $cacheHitRatio = $this->cacheHitRatio();
        $readingFromMemory = $cacheHitRatio >= $this->cacheThreshold();
        $activeSessions = $this->activeSessions();

        return [
            Stat::make('Database size', Bytes::human($this->totalBytes()))
                ->description(number_format($this->tables()).' tables')
                ->color('gray'),

            Stat::make('Cache hit ratio', number_format($cacheHitRatio * 100, 2).'%')
                ->description($readingFromMemory ? 'Reading from memory' : 'Going to disk')
                ->color($readingFromMemory ? 'success' : 'warning'),

            Stat::make('Sessions', (string) $this->sessions())
                ->description($activeSessions.' active')
                ->color($activeSessions > 0 ? 'info' : 'gray'),
        ];
    }

    /**
     * The database's size, as PostgreSQL itself reports it.
     *
     * This used to sum pg_total_relation_size() over every row of pg_stat_user_tables,
     * which has the server stat the files of every table, TOAST table and index one
     * relation at a time, outside any statement timeout. On a 160 GB database with ~500
     * tables that measured 8 seconds on a quiet server and 26 on average under load,
     * on every render of a card that polls. pg_database_size() walks the database
     * directory once and returns the same figure in about a second there.
     *
     * The two are not quite the same number: pg_database_size() also counts the system
     * catalogs and the schemas the panel leaves out. That is what "database size" means
     * to the person reading the card, and on the database above the difference was
     * 0.03%. It runs through the read-only executor, so it is bounded by the same
     * statement timeout as the package's other statistics queries.
     */
    private function totalBytes(): int
    {
        $row = app(ReadOnlyExecutor::class)->select('SELECT pg_database_size(current_database()) AS bytes')[0] ?? [];

        $bytes = $row['bytes'] ?? null;

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
