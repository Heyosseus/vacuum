<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum;

use Closure;
use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\BloatRule;
use Heyosseus\Vacuum\Advisor\CacheRule;
use Heyosseus\Vacuum\Advisor\ConfigurationRule;
use Heyosseus\Vacuum\Advisor\DuplicateRule;
use Heyosseus\Vacuum\Advisor\IndexRule;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Advisor\Inspections\BloatInspection;
use Heyosseus\Vacuum\Advisor\Inspections\CacheInspection;
use Heyosseus\Vacuum\Advisor\Inspections\ConfigurationInspection;
use Heyosseus\Vacuum\Advisor\Inspections\DuplicateInspection;
use Heyosseus\Vacuum\Advisor\Inspections\IndexInspection;
use Heyosseus\Vacuum\Advisor\Inspections\SessionInspection;
use Heyosseus\Vacuum\Advisor\Inspections\SettingInspection;
use Heyosseus\Vacuum\Advisor\Inspections\StatementInspection;
use Heyosseus\Vacuum\Advisor\Inspections\TableInspection;
use Heyosseus\Vacuum\Advisor\Rules\AutovacuumDisabled;
use Heyosseus\Vacuum\Advisor\Rules\AutovacuumWorkersVersusCost;
use Heyosseus\Vacuum\Advisor\Rules\BlockedSession;
use Heyosseus\Vacuum\Advisor\Rules\CacheHitRatio;
use Heyosseus\Vacuum\Advisor\Rules\DeadTuples;
use Heyosseus\Vacuum\Advisor\Rules\DuplicateIndex;
use Heyosseus\Vacuum\Advisor\Rules\EndOfLifeMajor;
use Heyosseus\Vacuum\Advisor\Rules\IdleInTransaction;
use Heyosseus\Vacuum\Advisor\Rules\InvalidIndex;
use Heyosseus\Vacuum\Advisor\Rules\IoTimingOff;
use Heyosseus\Vacuum\Advisor\Rules\LockTimeoutIneffective;
use Heyosseus\Vacuum\Advisor\Rules\MultixactWraparound;
use Heyosseus\Vacuum\Advisor\Rules\PendingRestart;
use Heyosseus\Vacuum\Advisor\Rules\SlowStatement;
use Heyosseus\Vacuum\Advisor\Rules\StaleStatistics;
use Heyosseus\Vacuum\Advisor\Rules\TableBloat;
use Heyosseus\Vacuum\Advisor\Rules\TimeoutsUnset;
use Heyosseus\Vacuum\Advisor\Rules\UnpatchedServer;
use Heyosseus\Vacuum\Advisor\Rules\UnusedIndex;
use Heyosseus\Vacuum\Advisor\Rules\Wraparound;
use Heyosseus\Vacuum\Advisor\SessionRule;
use Heyosseus\Vacuum\Advisor\SettingRule;
use Heyosseus\Vacuum\Advisor\StatementRule;
use Heyosseus\Vacuum\Advisor\TableRule;
use Heyosseus\Vacuum\Console\Commands\CheckCommand;
use Heyosseus\Vacuum\Console\Commands\InstallCommand;
use Heyosseus\Vacuum\Console\Commands\SnapshotCommand;
use Heyosseus\Vacuum\Filament\Install\PhpLintChecker;
use Heyosseus\Vacuum\Filament\Install\SyntaxChecker;
use Heyosseus\Vacuum\Filament\Support\HistoryPanel;
use Heyosseus\Vacuum\Filament\Support\PanelData;
use Heyosseus\Vacuum\Http\Middleware\Authorize;
use Heyosseus\Vacuum\Learn\Curriculum;
use Heyosseus\Vacuum\Learn\Lesson;
use Heyosseus\Vacuum\Learn\Lessons\DeadTuples as DeadTuplesLesson;
use Heyosseus\Vacuum\Learn\Lessons\Fillfactor as FillfactorLesson;
use Heyosseus\Vacuum\Learn\Lessons\RowVersions as RowVersionsLesson;
use Heyosseus\Vacuum\Learn\Lessons\UnusedIndexes as UnusedIndexesLesson;
use Heyosseus\Vacuum\Queries\BloatEstimates;
use Heyosseus\Vacuum\Queries\CacheStatistics;
use Heyosseus\Vacuum\Queries\IndexDuplicates;
use Heyosseus\Vacuum\Queries\IndexStatistics;
use Heyosseus\Vacuum\Queries\ServerCapabilities;
use Heyosseus\Vacuum\Queries\ServerSettings;
use Heyosseus\Vacuum\Queries\Sessions;
use Heyosseus\Vacuum\Queries\Statements;
use Heyosseus\Vacuum\Queries\TableStatistics;
use Heyosseus\Vacuum\Support\SqlRepository;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Console\Scheduling\Schedule;
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

    /** The same, for a rule that judges the full pg_settings audit -- context, source, pending_restart. */
    public const string CONFIGURATION_RULES = 'vacuum.configuration-rules';

    /** A whole subject of its own: a query paired with the rules that judge it. */
    public const string INSPECTIONS = 'vacuum.inspections';

    /** Tag a Lesson with this to have it appear in the Learn curriculum. */
    public const string LESSONS = 'vacuum.lessons';

    /**
     * The inspections registered so far, tagged together once registration ends.
     *
     * Accumulating them means an inspection is declared in exactly one place
     * rather than in a bind, a tag, and a list -- three edits that a new
     * inspection previously had to remember, and that a forgotten third made
     * silently invisible.
     *
     * @var list<class-string<Inspection>>
     */
    private array $inspections = [];

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

        // The History page's parts share one reading of history, the same way the
        // Overview's widgets share one run of the advisor.
        $this->app->scoped(HistoryPanel::class);

        $this->registerInspection(
            TableInspection::class,
            self::TABLE_RULES,
            TableRule::class,
            [DeadTuples::class, StaleStatistics::class, Wraparound::class, MultixactWraparound::class],
            fn (Application $app, array $rules): Inspection => new TableInspection(
                $app->make(TableStatistics::class),
                $rules,
            ),
        );

        $this->registerInspection(
            BloatInspection::class,
            self::BLOAT_RULES,
            BloatRule::class,
            [TableBloat::class],
            fn (Application $app, array $rules): Inspection => new BloatInspection(
                $app->make(BloatEstimates::class),
                $rules,
            ),
        );

        $this->registerInspection(
            IndexInspection::class,
            self::INDEX_RULES,
            IndexRule::class,
            [UnusedIndex::class, InvalidIndex::class],
            fn (Application $app, array $rules): Inspection => new IndexInspection(
                $app->make(IndexStatistics::class),
                $rules,
            ),
        );

        $this->registerInspection(
            DuplicateInspection::class,
            self::DUPLICATE_RULES,
            DuplicateRule::class,
            [DuplicateIndex::class],
            fn (Application $app, array $rules): Inspection => new DuplicateInspection(
                $app->make(IndexDuplicates::class),
                $rules,
            ),
        );

        $this->registerInspection(
            CacheInspection::class,
            self::CACHE_RULES,
            CacheRule::class,
            [CacheHitRatio::class],
            fn (Application $app, array $rules): Inspection => new CacheInspection(
                $app->make(Capabilities::class),
                $app->make(CacheStatistics::class),
                $rules,
            ),
        );

        $this->registerInspection(
            SessionInspection::class,
            self::SESSION_RULES,
            SessionRule::class,
            [IdleInTransaction::class, BlockedSession::class],
            fn (Application $app, array $rules): Inspection => new SessionInspection(
                $app->make(Capabilities::class),
                $app->make(Sessions::class),
                $rules,
            ),
        );

        $this->registerInspection(
            StatementInspection::class,
            self::STATEMENT_RULES,
            StatementRule::class,
            [SlowStatement::class],
            fn (Application $app, array $rules): Inspection => new StatementInspection(
                $app->make(Capabilities::class),
                $app->make(Statements::class),
                $rules,
            ),
        );

        $this->registerInspection(
            SettingInspection::class,
            self::SETTING_RULES,
            SettingRule::class,
            [AutovacuumDisabled::class, UnpatchedServer::class, EndOfLifeMajor::class],
            fn (Application $app, array $rules): Inspection => new SettingInspection(
                $app->make(Capabilities::class),
                $rules,
            ),
        );

        $this->registerInspection(
            ConfigurationInspection::class,
            self::CONFIGURATION_RULES,
            ConfigurationRule::class,
            [
                PendingRestart::class,
                TimeoutsUnset::class,
                LockTimeoutIneffective::class,
                IoTimingOff::class,
                AutovacuumWorkersVersusCost::class,
            ],
            fn (Application $app, array $rules): Inspection => new ConfigurationInspection(
                $app->make(ServerSettings::class),
                $rules,
            ),
        );

        $this->app->tag($this->inspections, self::INSPECTIONS);

        $this->app->bind(Advisor::class, function (Application $app): Advisor {
            $inspections = [];

            foreach ($app->tagged(self::INSPECTIONS) as $inspection) {
                if ($inspection instanceof Inspection) {
                    $inspections[] = $inspection;
                }
            }

            return new Advisor($inspections);
        });

        // Registration order is teaching order within a tier: byTier() keeps
        // lessons in the order they were tagged, so this list is also the
        // sequence a reader meets them in.
        $this->app->tag(
            [RowVersionsLesson::class, FillfactorLesson::class, DeadTuplesLesson::class, UnusedIndexesLesson::class],
            self::LESSONS,
        );

        $this->app->bind(
            Curriculum::class,
            fn (Application $app): Curriculum => new Curriculum($this->rules($app, self::LESSONS, Lesson::class)),
        );
    }

    /**
     * Register one inspection: its rules, its binding, and its place in the tag.
     *
     * @template TRule of object
     *
     * @param  class-string<Inspection>  $inspection
     * @param  class-string<TRule>  $contract
     * @param  list<class-string<TRule>>  $rules
     * @param  Closure(Application, list<TRule>): Inspection  $make
     */
    private function registerInspection(
        string $inspection,
        string $tag,
        string $contract,
        array $rules,
        Closure $make,
    ): void {
        $this->app->tag($rules, $tag);

        $this->app->bind(
            $inspection,
            fn (Application $app): Inspection => $make($app, $this->rules($app, $tag, $contract)),
        );

        $this->inspections[] = $inspection;
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

        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, InstallCommand::class, SnapshotCommand::class]);

            $this->publishes([
                __DIR__.'/../config/vacuum.php' => $this->app->configPath('vacuum.php'),
            ], 'vacuum-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'vacuum-migrations');

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
     * It stays silent unless Livewire is actually running: a Blade-only install has no
     * Livewire to register against, and neither does a bare unit test that boots this
     * provider alone, so the presence of the binding -- not merely the class -- is what
     * says there is a Livewire here to enrol the widgets into.
     */
    private function registerFilamentWidgets(): void
    {
        if (! class_exists(\Filament\Panel::class) || ! $this->app->bound('livewire')) {
            // A Blade-only application, or a unit test without the Livewire provider.
            return; // @codeCoverageIgnore
        }

        // The single-argument form lets Livewire derive the component's name from its
        // class itself, under whichever mechanism the installed version ships -- the
        // Finder in v4 (Filament v5), the ComponentRegistry in v3 (Filament v4) -- and
        // it is the same name Filament mounts the widget by, so the two always agree.
        foreach (Filament\VacuumPlugin::widgets() as $widget) {
            \Livewire\Livewire::component($widget);
        }
    }

    /**
     * Register the snapshot command on the scheduler, when history is on and the
     * application has left Vacuum to schedule it.
     *
     * A null cadence in config means the opposite: the application wires the command
     * into its own scheduler and Vacuum stays out of it. Either way this only ever
     * registers a read-then-write of the history tables; it adds nothing to the
     * inspected database's load beyond the queries the dashboard already runs.
     */
    private function registerSchedule(): void
    {
        $config = $this->app->make(Repository::class);

        if (! (bool) $config->get('vacuum.enabled', true)) {
            return;
        }

        if (! (bool) $config->get('vacuum.history.enabled', false)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($config): void {
            $cadence = $config->get('vacuum.history.schedule', 'hourly');

            // A null (or blank) cadence means the application schedules the command
            // itself, so Vacuum registers nothing.
            if (! is_string($cadence) || $cadence === '') {
                return;
            }

            // withoutOverlapping so a snapshot that runs long on a large database is
            // never started a second time on top of the first.
            $schedule->command('vacuum:snapshot')->withoutOverlapping()->{$this->cadenceMethod($cadence)}();
        });
    }

    /**
     * The scheduler frequency named by the configured cadence, or hourly when it
     * names nothing the scheduler recognises. An unknown value should not silently
     * stop the snapshots; it should fall back to a sensible default.
     */
    private function cadenceMethod(string $cadence): string
    {
        $recognised = [
            'everyMinute', 'everyFiveMinutes', 'everyTenMinutes', 'everyFifteenMinutes',
            'everyThirtyMinutes', 'hourly', 'daily', 'twiceDaily', 'weekly',
        ];

        return in_array($cadence, $recognised, true) ? $cadence : 'hourly';
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
