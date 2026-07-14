<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Rules\DeadTuples;
use Heyosseus\Vacuum\Advisor\TableRule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class VacuumServiceProvider extends ServiceProvider
{
    public const string TABLE_RULES = 'vacuum.table-rules';

    /**
     * Register the package's services into the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/vacuum.php', 'vacuum');

        $this->app->tag([DeadTuples::class], self::TABLE_RULES);

        $this->app->bind(Advisor::class, function (Application $app): Advisor {
            $rules = [];

            foreach ($app->tagged(self::TABLE_RULES) as $rule) {
                if ($rule instanceof TableRule) {
                    $rules[] = $rule;
                }
            }

            return new Advisor($rules);
        });
    }

    /**
     * Bootstrap the package's routes, views and publishable assets.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/vacuum.php' => $this->app->configPath('vacuum.php'),
            ], 'vacuum-config');
        }
    }
}
