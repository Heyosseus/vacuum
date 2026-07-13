<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum;

use Illuminate\Support\ServiceProvider;

final class VacuumServiceProvider extends ServiceProvider
{
    /**
     * Register the package's services into the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vacuum.php', 'vacuum');
    }

    /**
     * Bootstrap the package's routes, views and publishable assets.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/vacuum.php' => $this->app->configPath('vacuum.php'),
            ], 'vacuum-config');
        }
    }
}
