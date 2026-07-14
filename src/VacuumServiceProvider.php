<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Rules\DeadTuples;
use Heyosseus\Vacuum\Advisor\TableRule;
use Heyosseus\Vacuum\Http\Middleware\Authorize;
use Heyosseus\Vacuum\Queries\ServerCapabilities;
use Heyosseus\Vacuum\Support\SqlRepository;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Override;

final class VacuumServiceProvider extends ServiceProvider
{
    public const string TABLE_RULES = 'vacuum.table-rules';

    /**
     * Register the package's services into the container.
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vacuum.php', 'vacuum');

        $this->app->singleton(
            SqlRepository::class,
            static fn (): SqlRepository => new SqlRepository(__DIR__.'/../resources/sql'),
        );

        // Every panel wants to know what the server supports, and the answer
        // cannot change underneath a single request.
        $this->app->singleton(
            Capabilities::class,
            static fn (Application $app): Capabilities => $app->make(ServerCapabilities::class)->probe(),
        );

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
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/vacuum.php' => $this->app->configPath('vacuum.php'),
            ], 'vacuum-config');
        }
    }

    /**
     * The master switch is read here, at boot, rather than in the middleware:
     * a disabled Vacuum should have no routes to reach at all, not routes that
     * turn people away.
     */
    private function registerRoutes(): void
    {
        $config = $this->app->make(Repository::class);

        if (! (bool) $config->get('vacuum.enabled', true)) {
            return;
        }

        Route::group([
            'domain' => $config->get('vacuum.domain'),
            'prefix' => $config->get('vacuum.path', 'vacuum'),
            'middleware' => $this->middleware($config),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/vacuum.php');
        });
    }

    /**
     * The application's stack, with Vacuum's own door at the end of it. Authorize
     * is appended rather than configured so that it cannot be removed by
     * emptying the middleware array.
     *
     * @return list<string>
     */
    private function middleware(Repository $config): array
    {
        $configured = $config->get('vacuum.middleware', []);

        $stack = [];

        if (is_array($configured)) {
            foreach ($configured as $middleware) {
                if (is_string($middleware)) {
                    $stack[] = $middleware;
                }
            }
        }

        $stack[] = Authorize::class;

        return $stack;
    }
}
