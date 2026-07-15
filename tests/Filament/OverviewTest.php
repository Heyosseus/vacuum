<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Filament\Pages\Overview;
use Heyosseus\Vacuum\Filament\Support\PanelData;
use Heyosseus\Vacuum\Filament\Widgets\DatabaseVitals;
use Heyosseus\Vacuum\Filament\Widgets\FindingsBySeverity;
use Heyosseus\Vacuum\Filament\Widgets\FindingsList;
use Heyosseus\Vacuum\Filament\Widgets\HealthScore;
use Heyosseus\Vacuum\Filament\Widgets\IndexFootprint;
use Heyosseus\Vacuum\Filament\Widgets\LargestTables;
use Heyosseus\Vacuum\Filament\Widgets\RunningVacuums;
use Heyosseus\Vacuum\Vacuum;
use Illuminate\Contracts\View\View;
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
    DB::statement('SELECT pg_stat_force_next_flush()');
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
