<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Inspections\SettingInspection;
use Heyosseus\Vacuum\Advisor\Inspections\TableInspection;
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

    expect($inspections)->toHaveCount(8)
        ->and($inspections)->toContain(TableInspection::class)
        ->and($inspections)->toContain(SettingInspection::class);
});
