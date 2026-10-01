<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Database\ConnectionResolver;
use Heyosseus\Vacuum\Filament\Pages\Overview;
use Heyosseus\Vacuum\Filament\Support\PanelData;
use Heyosseus\Vacuum\Filament\Widgets\DatabaseVitals;
use Heyosseus\Vacuum\Filament\Widgets\FindingsBySeverity;
use Heyosseus\Vacuum\Filament\Widgets\FindingsList;
use Heyosseus\Vacuum\Filament\Widgets\HealthScore;
use Heyosseus\Vacuum\Filament\Widgets\IndexFootprint;
use Heyosseus\Vacuum\Filament\Widgets\LargestTables;
use Heyosseus\Vacuum\Filament\Widgets\RunningVacuums;
use Heyosseus\Vacuum\Support\Bytes;
use Heyosseus\Vacuum\Vacuum;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * The Overview's widgets read the same PostgreSQL the rest of the package does. Their
 * data methods are exercised directly -- one seam in from a full dashboard render, which
 * mounts each widget as a Livewire component of its own and is more than a package test
 * can stand up -- against a table left deliberately half-dead so the advisor has
 * something to say and every stat, chart and finding has a number to carry.
 */

beforeEach(function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);

    DB::statement('DROP TABLE IF EXISTS crates');
    DB::statement('CREATE TABLE crates (id serial PRIMARY KEY, label text)');
    DB::statement('CREATE INDEX crates_label_index ON crates (label)');
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 2000) i");
    DB::update("UPDATE crates SET label = label || '!'");
    flushStatistics();
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
});

it('sums the database into a health score and vitals', function (): void {
    $health = invokeProtected(app(HealthScore::class), 'getStats');
    $vitals = invokeProtected(app(DatabaseVitals::class), 'getStats');

    expect($health)->toHaveCount(3)
        ->and($vitals)->toHaveCount(3);
});

it('reports the database size PostgreSQL itself reports', function (): void {
    $bytes = DB::selectOne('SELECT pg_database_size(current_database()) AS bytes')->bytes;

    $vitals = invokeProtected(app(DatabaseVitals::class), 'getStats');

    expect($vitals[0]->getValue())->toBe(Bytes::human((int) $bytes));
});

it('reads each vital once per render, however many places the card shows it', function (): void {
    DB::flushQueryLog();
    DB::enableQueryLog();

    invokeProtected(app(DatabaseVitals::class), 'getStats');

    $queries = collect(DB::getQueryLog())->pluck('query');

    DB::disableQueryLog();

    expect($queries->filter(static fn (string $sql): bool => str_contains($sql, 'pg_stat_database')))->toHaveCount(1)
        ->and($queries->filter(static fn (string $sql): bool => str_contains($sql, "state = 'active'")))->toHaveCount(1)
        ->and($queries->filter(static fn (string $sql): bool => str_contains($sql, 'pg_total_relation_size')))->toBeEmpty();
});

it('does not poll the largest tables, which sizes every relation on each render', function (): void {
    expect(invokeProtected(app(LargestTables::class), 'getPollingInterval'))->toBeNull();
});

it('sizes the largest tables through the read-only executor, as PostgreSQL ranks them', function (): void {
    $expected = collect(DB::select(<<<'SQL'
        SELECT relname, pg_total_relation_size(relid) AS bytes
        FROM pg_stat_user_tables
        ORDER BY bytes DESC
        LIMIT 8
        SQL));

    DB::flushQueryLog();
    DB::enableQueryLog();

    $data = invokeProtected(app(LargestTables::class), 'getData');

    $queries = collect(DB::getQueryLog())->pluck('query');

    DB::disableQueryLog();

    expect($data['labels'])->toBe($expected->pluck('relname')->all())
        ->and($data['datasets'][0]['data'])->toBe($expected->map(static fn (object $row): float => round($row->bytes / 1024 / 1024, 1))->all())
        ->and($queries)->toContain('SET TRANSACTION READ ONLY')
        ->and($queries->filter(static fn (string $sql): bool => str_contains($sql, '"pg_stat_user_tables"')))->toBeEmpty();
});

it('leaves the ignored schemas out of the largest tables', function (): void {
    // A table large enough to top the chart, in a schema of its own, so ignoring that
    // schema is the only thing that can keep it off.
    DB::statement('CREATE SCHEMA vacuum_overview_ignored');

    try {
        DB::statement("CREATE TABLE vacuum_overview_ignored.hoard AS SELECT g AS id, repeat('x', 200) AS filler FROM generate_series(1, 50000) AS g");

        $widget = static fn (): LargestTables => app(LargestTables::class);

        expect(invokeProtected($widget(), 'getData')['labels'])->toContain('hoard');

        config()->set('vacuum.ignored_schemas', [...config('vacuum.ignored_schemas', []), 'vacuum_overview_ignored']);

        expect(invokeProtected($widget(), 'getData')['labels'])->not->toContain('hoard');
    } finally {
        DB::statement('DROP SCHEMA vacuum_overview_ignored CASCADE');
    }
});

/*
 * pg_total_relation_size() takes an ACCESS SHARE lock on each relation it sizes, so a
 * table held ACCESS EXCLUSIVE from a second connection stalls the chart's one query --
 * the same wait a busy server's DDL or a long migration puts it in, made on purpose.
 */
function holdingTableLock(Closure $while): void
{
    $default = config('database.default');
    config()->set('database.connections.vacuum_overview_locker', config("database.connections.{$default}"));

    DB::statement('CREATE TABLE vacuum_overview_locked (id int)');
    $locker = DB::connection('vacuum_overview_locker');

    try {
        $locker->beginTransaction();
        $locker->statement('LOCK TABLE vacuum_overview_locked IN ACCESS EXCLUSIVE MODE');

        $while();
    } finally {
        $locker->rollBack();
        DB::purge('vacuum_overview_locker');
        DB::statement('DROP TABLE vacuum_overview_locked');
    }
}

it('says the largest tables ran out of time rather than drawing an empty database', function (): void {
    holdingTableLock(static function (): void {
        $widget = app(LargestTables::class);

        expect($widget->getDescription())->toBe('Sizing every table took longer than the statement timeout allows.')
            ->and(invokeProtected($widget, 'getCachedData')['labels'])->toBe([]);
    });
});

it('lets any other failure of the largest tables surface as one', function (): void {
    // A lock_timeout shorter than the statement timeout fails the same wait with
    // lock_not_available rather than query_canceled, which is not the chart's to hide.
    $connection = app(ConnectionResolver::class)->resolve();
    $connection->statement("SET lock_timeout = '100ms'");

    try {
        holdingTableLock(static function (): void {
            expect(fn (): array => invokeProtected(app(LargestTables::class), 'getData'))
                ->toThrow(QueryException::class, 'lock timeout');
        });
    } finally {
        $connection->statement('RESET lock_timeout');
    }
});

it('shapes each chart from real numbers', function (): void {
    $severity = app(FindingsBySeverity::class);
    $largest = app(LargestTables::class);
    $footprint = app(IndexFootprint::class);

    expect(invokeProtected($severity, 'getType'))->toBe('doughnut')
        ->and(invokeProtected($severity, 'getData'))->toHaveKeys(['datasets', 'labels'])
        ->and(invokeProtected($largest, 'getType'))->toBe('bar')
        ->and(invokeProtected($largest, 'getData'))->toHaveKeys(['datasets', 'labels'])
        ->and(invokeProtected($footprint, 'getType'))->toBe('doughnut')
        ->and(invokeProtected($footprint, 'getData'))->toHaveKeys(['datasets', 'labels']);
});

it('lists the findings with a severity, a colour and a rail for each', function (): void {
    $widget = app(FindingsList::class);
    $findings = $widget->findings();

    expect($findings)->not->toBeEmpty();

    $finding = $findings[0];

    // triage() walks all three severities, so it covers every colour and label the list
    // can paint, whether or not a severity has any findings this run.
    expect($widget->triage())->not->toBeEmpty()
        ->and($widget->color($finding))->toBeString()
        ->and($widget->label($finding->severity))->toBeString()
        ->and($widget->rail($finding))->toStartWith('#')
        ->and($widget->render())->toBeInstanceOf(View::class);
});

it('reads the running vacuums, of which there are none mid-test', function (): void {
    $widget = app(RunningVacuums::class);

    expect($widget->vacuums())->toBeArray()
        ->and($widget->render())->toBeInstanceOf(View::class);
});

it('runs the advisor once and hands every widget the same findings', function (): void {
    $data = app(PanelData::class);

    expect($data->findings())->toBeArray()
        ->and($data->health()->score)->toBeInt()
        // The second call is the memoised one; it must be the very same list.
        ->and($data->findings())->toBe($data->findings());
});

it('places the overview in the vacuum group with its widgets', function (): void {
    expect(Overview::getNavigationGroup())->toBe('Vacuum')
        ->and(Overview::getSlug())->toBe('vacuum')
        ->and(Overview::canAccess())->toBeTrue();

    $page = app(Overview::class);

    expect(invokeProtected($page, 'getHeaderWidgets'))->toContain(HealthScore::class)
        ->and($page->getHeaderWidgetsColumns())->toBe(3);
});

it('closes the overview to a stranger, exactly as the dashboard is', function (): void {
    Vacuum::auth(static fn (Request $request): bool => false);

    expect(Overview::canAccess())->toBeFalse();
});
