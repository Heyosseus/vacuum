<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Internals\Availability;

it('says what is missing and what would fix it', function (): void {
    $missing = Availability::missingExtension('pageinspect');

    expect($missing->available)->toBeFalse()
        ->and($missing->reason)->toContain('pageinspect')
        ->and($missing->remedy)->toBe('CREATE EXTENSION pageinspect;');
});

it('names the grant when the extension is there but the role is not allowed', function (): void {
    $denied = Availability::insufficientPrivilege('pg_monitor');

    expect($denied->available)->toBeFalse()
        ->and($denied->remedy)->toBe('GRANT pg_monitor TO current_user;');
});

it('carries no reason when it can actually run', function (): void {
    expect(Availability::available()->available)->toBeTrue()
        ->and(Availability::available()->reason)->toBeNull();
});

it('says so when the whole surface is switched off', function (): void {
    expect(Availability::disabled()->available)->toBeFalse()
        ->and(Availability::disabled()->reason)->toContain('disabled');
});
