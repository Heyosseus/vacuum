<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Heyosseus\Vacuum\Filament\Pages\Overview;
use Heyosseus\Vacuum\Filament\Resources\IndexResource;
use Heyosseus\Vacuum\Filament\Resources\SessionResource;
use Heyosseus\Vacuum\Filament\Resources\StatementResource;
use Heyosseus\Vacuum\Filament\Resources\TableResource;
use Heyosseus\Vacuum\Filament\Widgets\DatabaseVitals;
use Heyosseus\Vacuum\Filament\Widgets\FindingsBySeverity;
use Heyosseus\Vacuum\Filament\Widgets\FindingsList;
use Heyosseus\Vacuum\Filament\Widgets\HealthScore;
use Heyosseus\Vacuum\Filament\Widgets\IndexFootprint;
use Heyosseus\Vacuum\Filament\Widgets\LargestTables;
use Heyosseus\Vacuum\Filament\Widgets\RunningVacuums;

/**
 * Vacuum, added to a Filament panel. The installer splices
 * `->plugin(VacuumPlugin::make())` into a panel provider; this is what that call builds.
 * It registers Vacuum's surfaces on the panel and nothing else -- the authorization is
 * each surface's own, so that one Vacuum::auth callback still governs who may look,
 * whether they arrive through Blade or through Filament.
 *
 * Everything lands in the "Vacuum" navigation group: the Overview dashboard first, then
 * the Tables, Indexes, Sessions and Statements resources. The Statements resource hides
 * itself where pg_stat_statements is not installed.
 */
final class VacuumPlugin implements Plugin
{
    /**
     * The Overview's widgets. They are registered as Livewire components by hand -- see
     * VacuumServiceProvider -- rather than through $panel->widgets(), which would also
     * enrol them in the panel's widget pool and scatter them across the application's own
     * dashboard. The Overview names them itself, so they only need to be resolvable, not
     * offered to every page.
     *
     * @var list<class-string<\Filament\Widgets\Widget>>
     */
    private const array WIDGETS = [
        HealthScore::class,
        DatabaseVitals::class,
        FindingsBySeverity::class,
        LargestTables::class,
        IndexFootprint::class,
        FindingsList::class,
        RunningVacuums::class,
    ];

    public static function make(): self
    {
        return new self;
    }

    /**
     * The Overview's widget classes, for the service provider to register as Livewire
     * components early in every request.
     *
     * @return list<class-string<\Filament\Widgets\Widget>>
     */
    public static function widgets(): array
    {
        return self::WIDGETS;
    }

    public function getId(): string
    {
        return 'vacuum';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->pages([
                Overview::class,
            ])
            ->resources([
                TableResource::class,
                IndexResource::class,
                SessionResource::class,
                StatementResource::class,
            ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
