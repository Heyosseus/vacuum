<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Filament\Widgets\DatabaseVitals;
use Heyosseus\Vacuum\Filament\Widgets\FindingsBySeverity;
use Heyosseus\Vacuum\Filament\Widgets\FindingsList;
use Heyosseus\Vacuum\Filament\Widgets\HealthScore;
use Heyosseus\Vacuum\Filament\Widgets\HealthTimeline;
use Heyosseus\Vacuum\Filament\Widgets\IndexFootprint;
use Heyosseus\Vacuum\Filament\Widgets\LargestTables;
use Heyosseus\Vacuum\Filament\Widgets\RunningVacuums;
use Heyosseus\Vacuum\Vacuum;
use Illuminate\Http\Request;

/**
 * Every widget is registered as a global Livewire component, which means each one
 * is a door of its own rather than a part of the Overview page that contains it.
 * Authorization came only from that page. Mounting a Livewire component takes a
 * signed snapshot, so this was never the easy way in — but a surface that reads
 * the server's catalogs should not depend on its container remembering to ask.
 * One gate, on every door.
 */
$widgets = [
    DatabaseVitals::class,
    FindingsBySeverity::class,
    FindingsList::class,
    HealthScore::class,
    HealthTimeline::class,
    IndexFootprint::class,
    LargestTables::class,
    RunningVacuums::class,
];

it('shows a widget to a reader the auth callback admits', function (string $widget): void {
    Vacuum::auth(static fn (Request $request): bool => true);

    expect($widget::canView())->toBeTrue();
})->with($widgets);

it('hides a widget from a reader the auth callback turns away', function (string $widget): void {
    Vacuum::auth(static fn (Request $request): bool => false);

    expect($widget::canView())->toBeFalse();
})->with($widgets);
