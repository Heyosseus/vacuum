<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Filament\Widgets\FindingsList;
use Heyosseus\Vacuum\History\FindingView;
use Heyosseus\Vacuum\History\Forecast;
use Heyosseus\Vacuum\History\Trend;

function tableView(Trend $trend, ?Forecast $forecast = null): FindingView
{
    $finding = new Finding(
        rule: 'wraparound',
        subject: 'public.orders',
        severity: Severity::Warning,
        summary: 's',
        impact: 'i',
        table: 'public.orders',
    );

    return new FindingView($finding, $trend, $forecast, null);
}

it('turns a rising view into a red chip and an easing one into a green chip', function (): void {
    $widget = app(FindingsList::class);

    expect($widget->trend(tableView(Trend::Rising)))->toBe(['symbol' => '▲', 'label' => 'rising', 'color' => '#e11d48'])
        ->and($widget->trend(tableView(Trend::Falling))['label'])->toBe('easing')
        ->and($widget->trend(tableView(Trend::Flat)))->toBeNull()
        ->and($widget->trend(tableView(Trend::Unknown)))->toBeNull();
});

it('phrases a forecast, and shows nothing when there is none', function (): void {
    $widget = app(FindingsList::class);

    $imminent = tableView(Trend::Rising, new Forecast(CarbonImmutable::parse('2026-08-01'), 0, 1.0));
    $days = tableView(Trend::Rising, new Forecast(CarbonImmutable::parse('2026-08-10'), 9, 1.0));

    expect($widget->forecast(tableView(Trend::Rising)))->toBeNull()
        ->and($widget->forecast($imminent))->toContain('imminently')
        ->and($widget->forecast($days))->toContain('about 9 days');
});

it('hands the list its finding views', function (): void {
    expect(app(FindingsList::class)->findingViews())->toBeArray();
});
