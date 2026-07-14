<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\BloatRule;
use Heyosseus\Vacuum\Advisor\CacheRule;
use Heyosseus\Vacuum\Advisor\IndexRule;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Advisor\Inspections\BloatInspection;
use Heyosseus\Vacuum\Advisor\Inspections\CacheInspection;
use Heyosseus\Vacuum\Advisor\Inspections\IndexInspection;
use Heyosseus\Vacuum\Advisor\Inspections\SessionInspection;
use Heyosseus\Vacuum\Advisor\Inspections\TableInspection;
use Heyosseus\Vacuum\Advisor\Rules\BlockedSession;
use Heyosseus\Vacuum\Advisor\Rules\CacheHitRatio;
use Heyosseus\Vacuum\Advisor\Rules\DeadTuples;
use Heyosseus\Vacuum\Advisor\Rules\IdleInTransaction;
use Heyosseus\Vacuum\Advisor\Rules\TableBloat;
use Heyosseus\Vacuum\Advisor\Rules\UnusedIndex;
use Heyosseus\Vacuum\Advisor\SessionRule;
use Heyosseus\Vacuum\Advisor\TableRule;
use Heyosseus\Vacuum\Http\Middleware\Authorize;
use Heyosseus\Vacuum\Queries\BloatEstimates;
use Heyosseus\Vacuum\Queries\CacheStatistics;
use Heyosseus\Vacuum\Queries\IndexStatistics;
use Heyosseus\Vacuum\Queries\ServerCapabilities;
use Heyosseus\Vacuum\Queries\Sessions;
use Heyosseus\Vacuum\Queries\TableStatistics;
use Heyosseus\Vacuum\Support\SqlRepository;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Override;

final class VacuumServiceProvider extends ServiceProvider
{
    /** Tag an application's own TableRule with this to have it inspected too. */
    public const string TABLE_RULES = 'vacuum.table-rules';

    /** The same, for a rule that judges wasted space rather than dead tuples. */
    public const string BLOAT_RULES = 'vacuum.bloat-rules';

    /** The same, for a rule that judges an index. */
    public const string INDEX_RULES = 'vacuum.index-rules';

    /** The same, for a rule that judges how much reading went to disk. */
    public const string CACHE_RULES = 'vacuum.cache-rules';

    /** The same, for a rule that judges what a connection is doing. */
    public const string SESSION_RULES = 'vacuum.session-rules';

    /** A whole subject of its own: a query paired with the rules that judge it. */
    public const string INSPECTIONS = 'vacuum.inspections';

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
        $this->app->tag([TableBloat::class], self::BLOAT_RULES);
        $this->app->tag([UnusedIndex::class], self::INDEX_RULES);
        $this->app->tag([CacheHitRatio::class], self::CACHE_RULES);
        $this->app->tag([IdleInTransaction::class, BlockedSession::class], self::SESSION_RULES);

        $this->app->bind(TableInspection::class, fn (Application $app): TableInspection => new TableInspection(
            $app->make(TableStatistics::class),
            $this->rules($app, self::TABLE_RULES, TableRule::class),
        ));

        $this->app->bind(BloatInspection::class, fn (Application $app): BloatInspection => new BloatInspection(
            $app->make(BloatEstimates::class),
            $this->rules($app, self::BLOAT_RULES, BloatRule::class),
        ));

        $this->app->bind(IndexInspection::class, fn (Application $app): IndexInspection => new IndexInspection(
            $app->make(IndexStatistics::class),
            $this->rules($app, self::INDEX_RULES, IndexRule::class),
        ));

        $this->app->bind(CacheInspection::class, fn (Application $app): CacheInspection => new CacheInspection(
            $app->make(Capabilities::class),
            $app->make(CacheStatistics::class),
            $this->rules($app, self::CACHE_RULES, CacheRule::class),
        ));

        $this->app->bind(SessionInspection::class, fn (Application $app): SessionInspection => new SessionInspection(
            $app->make(Capabilities::class),
            $app->make(Sessions::class),
            $this->rules($app, self::SESSION_RULES, SessionRule::class),
        ));

        $this->app->tag([
            TableInspection::class,
            BloatInspection::class,
            IndexInspection::class,
            CacheInspection::class,
            SessionInspection::class,
        ], self::INSPECTIONS);

        $this->app->bind(Advisor::class, function (Application $app): Advisor {
            $inspections = [];

            foreach ($app->tagged(self::INSPECTIONS) as $inspection) {
                if ($inspection instanceof Inspection) {
                    $inspections[] = $inspection;
                }
            }

            return new Advisor($inspections);
        });
    }

    /**
     * The rules an application has tagged, and only the ones the inspection can
     * actually put a question to. The container types a tag as a bare iterable,
     * so anything at all could be in it.
     *
     * @template TRule of object
     *
     * @param  class-string<TRule>  $contract
     * @return list<TRule>
     */
    private function rules(Application $app, string $tag, string $contract): array
    {
        $rules = [];

        foreach ($app->tagged($tag) as $rule) {
            if ($rule instanceof $contract) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Bootstrap the package's routes, views and publishable assets.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'vacuum');

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/vacuum.php' => $this->app->configPath('vacuum.php'),
            ], 'vacuum-config');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/vacuum'),
            ], 'vacuum-views');
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
