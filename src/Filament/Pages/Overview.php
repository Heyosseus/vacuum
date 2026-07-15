<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Heyosseus\Vacuum\Filament\Widgets\DatabaseVitals;
use Heyosseus\Vacuum\Filament\Widgets\FindingsBySeverity;
use Heyosseus\Vacuum\Filament\Widgets\FindingsList;
use Heyosseus\Vacuum\Filament\Widgets\HealthScore;
use Heyosseus\Vacuum\Filament\Widgets\IndexFootprint;
use Heyosseus\Vacuum\Filament\Widgets\LargestTables;
use Heyosseus\Vacuum\Filament\Widgets\RunningVacuums;
use Heyosseus\Vacuum\Vacuum;
use Override;

/**
 * The health story at a glance, and the group's landing page.
 *
 * A plain page rather than a second Dashboard: a panel already owns one dashboard -- the
 * application's home -- and registering a Dashboard subclass beside it collides with that
 * primary, which is felt hardest in a tenant panel. An ordinary page carries the same
 * widgets in its header and lays them out through Filament's own grid, so the score, the
 * vitals, the charts and the findings render without a hand-written view and without
 * fighting the panel for the root route.
 */
final class Overview extends Page
{
    protected static ?string $navigationLabel = 'Overview';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static ?string $title = 'Vacuum';

    /**
     * The group and slug are given as methods rather than the `$navigationGroup` and
     * `$slug` properties, which Filament declares a level up the inheritance chain and in
     * a trait: the "privatise unused final properties" refactor cannot see through either,
     * and would wrongly seal them away from the parent that reads them.
     */
    #[Override]
    public static function getNavigationGroup(): string
    {
        return 'Vacuum';
    }

    #[Override]
    public static function getSlug(?Panel $panel = null): string
    {
        return 'vacuum';
    }

    /** The one gate again: reachable exactly when the dashboard is. */
    #[Override]
    public static function canAccess(): bool
    {
        return Vacuum::check(request());
    }

    /**
     * The widgets ride in the page header, which is where an ordinary Filament page lays
     * out a widget grid without a custom view.
     *
     * @return array<class-string<Widget>|WidgetConfiguration>
     */
    #[Override]
    protected function getHeaderWidgets(): array
    {
        return [
            HealthScore::class,
            DatabaseVitals::class,
            FindingsBySeverity::class,
            LargestTables::class,
            IndexFootprint::class,
            FindingsList::class,
            RunningVacuums::class,
        ];
    }

    #[Override]
    public function getHeaderWidgetsColumns(): int
    {
        return 3;
    }
}
