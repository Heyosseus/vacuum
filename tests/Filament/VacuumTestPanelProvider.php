<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Tests\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Heyosseus\Vacuum\Filament\VacuumPlugin;

/**
 * The smallest real panel Vacuum's plugin can be added to. It exists only so the
 * smoke tests can mount the resource's pages the way an application would, through
 * a booted panel, rather than testing the classes in a vacuum where the panel is
 * imagined. The plugin is registered exactly as the installer would register it.
 */
final class VacuumTestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(VacuumPlugin::make());
    }
}
