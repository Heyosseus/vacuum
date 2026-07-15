<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\BloatRule;
use Heyosseus\Vacuum\Advisor\CacheRule;
use Heyosseus\Vacuum\Advisor\DuplicateRule;
use Heyosseus\Vacuum\Advisor\IndexRule;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Advisor\Inspections\BloatInspection;
use Heyosseus\Vacuum\Advisor\Inspections\CacheInspection;
use Heyosseus\Vacuum\Advisor\Inspections\DuplicateInspection;
use Heyosseus\Vacuum\Advisor\Inspections\IndexInspection;
use Heyosseus\Vacuum\Advisor\Inspections\SessionInspection;
use Heyosseus\Vacuum\Advisor\Inspections\SettingInspection;
use Heyosseus\Vacuum\Advisor\Inspections\StatementInspection;
use Heyosseus\Vacuum\Advisor\Inspections\TableInspection;
use Heyosseus\Vacuum\Advisor\Rules\AutovacuumDisabled;
use Heyosseus\Vacuum\Advisor\Rules\BlockedSession;
use Heyosseus\Vacuum\Advisor\Rules\CacheHitRatio;
use Heyosseus\Vacuum\Advisor\Rules\DeadTuples;
use Heyosseus\Vacuum\Advisor\Rules\DuplicateIndex;
use Heyosseus\Vacuum\Advisor\Rules\IdleInTransaction;
use Heyosseus\Vacuum\Advisor\Rules\InvalidIndex;
use Heyosseus\Vacuum\Advisor\Rules\SlowStatement;
use Heyosseus\Vacuum\Advisor\Rules\StaleStatistics;
use Heyosseus\Vacuum\Advisor\Rules\TableBloat;
use Heyosseus\Vacuum\Advisor\Rules\UnusedIndex;
use Heyosseus\Vacuum\Advisor\Rules\Wraparound;
use Heyosseus\Vacuum\Advisor\SessionRule;
use Heyosseus\Vacuum\Advisor\SettingRule;
use Heyosseus\Vacuum\Advisor\StatementRule;
use Heyosseus\Vacuum\Advisor\TableRule;
use Heyosseus\Vacuum\Console\Commands\CheckCommand;
use Heyosseus\Vacuum\Console\Commands\InstallCommand;
use Heyosseus\Vacuum\Filament\Install\PhpLintChecker;
use Heyosseus\Vacuum\Filament\Install\SyntaxChecker;
use Heyosseus\Vacuum\Filament\Support\PanelData;
use Heyosseus\Vacuum\Http\Middleware\Authorize;
use Heyosseus\Vacuum\Queries\BloatEstimates;
use Heyosseus\Vacuum\Queries\CacheStatistics;
use Heyosseus\Vacuum\Queries\IndexDuplicates;
use Heyosseus\Vacuum\Queries\IndexStatistics;
use Heyosseus\Vacuum\Queries\ServerCapabilities;
use Heyosseus\Vacuum\Queries\Sessions;
use Heyosseus\Vacuum\Queries\Statements;
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

    /** The same, for a rule that judges an index against the one it copies. */
    public const string DUPLICATE_RULES = 'vacuum.duplicate-rules';

    /** The same, for a rule that judges how much reading went to disk. */
    public const string CACHE_RULES = 'vacuum.cache-rules';

    /** The same, for a rule that judges what a connection is doing. */
    public const string SESSION_RULES = 'vacuum.session-rules';

    /** The same, for a rule that judges a query pg_stat_statements has watched. */
    public const string STATEMENT_RULES = 'vacuum.statement-rules';

    /** The same, for a rule that judges how the server itself is configured. */
    public const string SETTING_RULES = 'vacuum.setting-rules';

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

        // The installer verifies its own edits by handing the file to PHP itself.
        $this->app->bind(SyntaxChecker::class, PhpLintChecker::class);

        // Every panel wants to know what the server supports, and the answer
        // cannot change underneath a single request.
        $this->app->singleton(
            Capabilities::class,
            static fn (Application $app): Capabilities => $app->make(ServerCapabilities::class)->probe(),
        );

        // The Overview's widgets share one inspection: scoped so it runs the advisor
        // once per request and is forgotten between them, rather than caching a stale
        // health score into the next page load.
        $this->app->scoped(PanelData::class);

        $this->app->tag([DeadTuples::class, StaleStatistics::class, Wraparound::class], self::TABLE_RULES);
        $this->app->tag([TableBloat::class], self::BLOAT_RULES);
        $this->app->tag([UnusedIndex::class, InvalidIndex::class], self::INDEX_RULES);
        $this->app->tag([DuplicateIndex::class], self::DUPLICATE_RULES);
        $this->app->tag([CacheHitRatio::class], self::CACHE_RULES);
        $this->app->tag([IdleInTransaction::class, BlockedSession::class], self::SESSION_RULES);
        $this->app->tag([SlowStatement::class], self::STATEMENT_RULES);
        $this->app->tag([AutovacuumDisabled::class], self::SETTING_RULES);

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

        $this->app->bind(DuplicateInspection::class, fn (Application $app): DuplicateInspection => new DuplicateInspection(
            $app->make(IndexDuplicates::class),
            $this->rules($app, self::DUPLICATE_RULES, DuplicateRule::class),
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

        $this->app->bind(StatementInspection::class, fn (Application $app): StatementInspection => new StatementInspection(
            $app->make(Capabilities::class),
            $app->make(Statements::class),
            $this->rules($app, self::STATEMENT_RULES, StatementRule::class),
        ));

        $this->app->bind(SettingInspection::class, fn (Application $app): SettingInspection => new SettingInspection(
            $app->make(Capabilities::class),
            $this->rules($app, self::SETTING_RULES, SettingRule::class),
        ));

        $this->app->tag([
            TableInspection::class,
            BloatInspection::class,
            IndexInspection::class,
            DuplicateInspection::class,
            CacheInspection::class,
            SessionInspection::class,
            StatementInspection::class,
            SettingInspection::class,
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

        $this->registerFilamentWidgets();

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, InstallCommand::class]);

            $this->publishes([
                __DIR__.'/../config/vacuum.php' => $this->app->configPath('vacuum.php'),
            ], 'vacuum-config');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/vacuum'),
            ], 'vacuum-views');
        }
    }

    /**
     * Enrol the Overview's widgets as Livewire components.
     *
     * Filament registers the Livewire components for a panel's pages and for a resource's
     * widgets, but not for the widgets a standalone page places in its header, which is
     * what the Overview does. So they are registered here instead -- under the same names
     * Filament would give them -- early and unconditionally in every request, rather than
     * in the plugin's boot, whose timing sits behind Filament's own component caching.
     *
     * It stays silent for a Blade-only install: without Filament and Livewire there are
     * no widgets to place and nothing to register.
     */
    private function registerFilamentWidgets(): void
    {
        if (! class_exists(\Filament\Panel::class) || ! class_exists(\Livewire\Livewire::class)) {
            return;
        }

        $registry = $this->app->make(\Livewire\Mechanisms\ComponentRegistry::class);

        foreach (Filament\VacuumPlugin::widgets() as $widget) {
            \Livewire\Livewire::component($registry->getName($widget), $widget);
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

        if ($config->get('vacuum.ui', 'blade') === 'filament') {
            return;
        }

        // The console is off unless the application asks for it, and off means the
        // route is not there. A console that merely refuses to run things needs
        // only one bug to run them.
        $console = (bool) $config->get('vacuum.console.enabled', false);

        Route::group([
            'domain' => $config->get('vacuum.domain'),
            'prefix' => $config->get('vacuum.path', 'vacuum'),
            'middleware' => $this->middleware($config),
        ], function () use ($console): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/vacuum.php');

            if ($console) {
                $this->loadRoutesFrom(__DIR__.'/../routes/console.php');
            }
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
