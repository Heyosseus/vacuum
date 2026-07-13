<?php

declare(strict_types=1);

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
