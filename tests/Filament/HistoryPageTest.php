<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Filament\Pages\History;
use Heyosseus\Vacuum\Filament\Support\HistoryPanel;
use Heyosseus\Vacuum\Filament\Widgets\HealthTimeline;
use Heyosseus\Vacuum\History\Models\Snapshot;
use Heyosseus\Vacuum\Vacuum;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\Request;

/*
 * The Filament History page and its one widget, boot with history switched on and its
 * tables migrated around each test — the page does not exist otherwise.
 */

function migration(): Migration
{
    return require __DIR__.'/../../database/migrations/create_vacuum_history_tables.php';
}

beforeEach(function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);
    config()->set('vacuum.history.enabled', true);

    migration()->down();
    migration()->up();
});

afterEach(function (): void {
    migration()->down();
});

it('places history in the vacuum group, gated and navigable when it is on', function (): void {
    expect(History::getNavigationGroup())->toBe('Vacuum')
        ->and(History::getSlug())->toBe('vacuum-history')
        ->and(History::canAccess())->toBeTrue()
        ->and(History::shouldRegisterNavigation())->toBeTrue();

    $page = app(History::class);

    expect(invokeProtected($page, 'getHeaderWidgets'))->toContain(HealthTimeline::class)
        ->and($page->getHeaderWidgetsColumns())->toBe(1);
});

it('hides history entirely when it is switched off', function (): void {
    config()->set('vacuum.history.enabled', false);

    expect(History::canAccess())->toBeFalse()
        ->and(History::shouldRegisterNavigation())->toBeFalse();
});

it('closes history to a stranger, exactly as the overview is', function (): void {
    Vacuum::auth(static fn (Request $request): bool => false);

    expect(History::canAccess())->toBeFalse();
});

it('reads the panel and renders the timeline widget', function (): void {
    $widget = app(HealthTimeline::class);

    expect($widget->panel())->toBeInstanceOf(HistoryPanel::class)
        ->and($widget->render())->toBeInstanceOf(View::class);
});

it('assembles the health line, regressions and forecasts once for the page', function (): void {
    $previous = Snapshot::create([
        'connection' => 'pgsql', 'taken_at' => CarbonImmutable::parse('2026-04-01 00:00:00'),
        'created_at' => CarbonImmutable::parse('2026-04-01 00:00:00'),
        'server_version' => 170000, 'health_score' => 70, 'grade' => 'C',
    ]);
    $previous->findings()->create(['rule' => 'blocked-session', 'subject' => 'pid 9', 'severity' => 'warning', 'summary' => 's']);

    Snapshot::create([
        'connection' => 'pgsql', 'taken_at' => CarbonImmutable::parse('2026-04-02 00:00:00'),
        'created_at' => CarbonImmutable::parse('2026-04-02 00:00:00'),
        'server_version' => 170000, 'health_score' => 90, 'grade' => 'A',
    ]);

    $panel = app(HistoryPanel::class);

    expect($panel->enabled())->toBeTrue()
        ->and($panel->hasHistory())->toBeTrue()
        ->and($panel->scores())->toHaveCount(2)
        ->and($panel->latestScore())->toBe(90)
        ->and($panel->healthSparkline())->toBeString()
        ->and($panel->healthSparkline())->not->toBe('')
        ->and($panel->clearedFindings())->toHaveCount(1)
        ->and($panel->newFindings())->toBeArray()
        ->and($panel->forecasts())->toBeArray();
});

it('has no score and no history before a snapshot is taken', function (): void {
    $panel = app(HistoryPanel::class);

    expect($panel->hasHistory())->toBeFalse()
        ->and($panel->latestScore())->toBeNull()
        ->and($panel->healthSparkline())->toBe('');
});
