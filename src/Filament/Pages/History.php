<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Heyosseus\Vacuum\Filament\Widgets\HealthTimeline;
use Heyosseus\Vacuum\History\History as HistoryReader;
use Heyosseus\Vacuum\Vacuum;
use Override;

/**
 * The database over time: the health line, what is newly wrong, what has cleared,
 * and what is forecast to break.
 *
 * It appears in the navigation only where history is switched on, because with it
 * off there is nothing recorded to show. The gate is otherwise the Overview's: it is
 * reachable exactly when the dashboard is.
 */
final class History extends Page
{
    protected static ?string $navigationLabel = 'History';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $title = 'History';

    #[Override]
    public static function getNavigationGroup(): string
    {
        return 'Vacuum';
    }

    #[Override]
    public static function getSlug(?Panel $panel = null): string
    {
        return 'vacuum-history';
    }

    /** The dashboard's gate, and history switched on: nothing to show otherwise. */
    #[Override]
    public static function canAccess(): bool
    {
        return Vacuum::check(request()) && app(HistoryReader::class)->enabled();
    }

    /** Off the navigation entirely until history is recording. */
    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return app(HistoryReader::class)->enabled();
    }

    /**
     * @return array<class-string<Widget>|WidgetConfiguration>
     */
    #[Override]
    protected function getHeaderWidgets(): array
    {
        return [HealthTimeline::class];
    }

    #[Override]
    public function getHeaderWidgetsColumns(): int
    {
        return 1;
    }
}
