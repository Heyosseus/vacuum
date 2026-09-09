<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspections\ConfigurationInspection;
use Heyosseus\Vacuum\Advisor\Inspections\SettingInspection;
use Heyosseus\Vacuum\Advisor\Inspections\TableInspection;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Schema\MigrationMap;
use Heyosseus\Vacuum\VacuumServiceProvider;

it('merges the package configuration into the application', function (): void {
    expect(config('vacuum.path'))->toBe('vacuum')
        ->and(config('vacuum.enabled'))->toBeTrue();
});

it('keeps the SQL console switched off until it is deliberately enabled', function (): void {
    expect(config('vacuum.console.enabled'))->toBeFalse();
});

it('publishes the configuration file under the vacuum-config tag', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'vacuum-config'])
        ->assertSuccessful();
});

it('resolves every tagged inspection through the registration helper', function (): void {
    $inspections = [];

    foreach (app()->tagged(VacuumServiceProvider::INSPECTIONS) as $inspection) {
        $inspections[] = $inspection::class;
    }

    expect($inspections)->toHaveCount(9)
        ->and($inspections)->toContain(TableInspection::class)
        ->and($inspections)->toContain(SettingInspection::class)
        ->and($inspections)->toContain(ConfigurationInspection::class);
});

it('points the migration map at database_path(migrations) when none is configured', function (): void {
    $finding = new Finding(
        rule: 'unindexed-foreign-key',
        subject: 'public.orders.customer_id',
        severity: Severity::Warning,
        summary: 'stub',
        impact: 'stub',
        table: 'public.orders',
    );

    // The default application has no such migration, so this proves the map
    // was built at all -- not what it finds.
    expect(app(MigrationMap::class)->locate($finding))->toBeNull();
});

it('points the migration map at the configured migrations path', function (): void {
    config(['vacuum.lint.migrations_path' => __DIR__.'/../fixtures/migrations']);

    $finding = new Finding(
        rule: 'unindexed-foreign-key',
        subject: 'public.orders.customer_id',
        severity: Severity::Warning,
        summary: 'stub',
        impact: 'stub',
        table: 'public.orders',
    );

    expect(app(MigrationMap::class)->locate($finding)?->line)->toBe(15);
});
