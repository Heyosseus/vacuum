<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Tests;

use Heyosseus\Vacuum\Tests\Filament\VacuumTestPanelProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ViewErrorBag;
use Override;

/**
 * Boots a real Filament panel with Vacuum's plugin on it, so the smoke tests mount
 * the resource's pages through the panel an application would have, not a stub.
 * Filament's own packages register themselves through Laravel's package discovery;
 * only the panel is the application's to declare, so only the panel is added here.
 */
abstract class FilamentTestCase extends TestCase
{
    /**
     * Filament's view pages render their schema through a nested `@livewire`
     * component, and a nested component mounted straight from a test never runs the
     * middleware that shares the request's error bag, so the first thing its blade
     * asks for -- `$errors` -- is null. Sharing an empty bag up front gives every
     * nested render something real to read.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['session']->start();
        $this->app['view']->share('errors', new ViewErrorBag);
    }

    /**
     * Testbench does not run Laravel's package discovery, so the packages Filament
     * itself relies on -- Livewire, the icon sets, the blade directives -- are named
     * here by hand, in dependency order, followed by Filament's own providers and
     * last the panel that carries Vacuum's plugin.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),

            \Livewire\LivewireServiceProvider::class,
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,

            \Filament\Support\SupportServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,

            VacuumTestPanelProvider::class,
        ];
    }
}
