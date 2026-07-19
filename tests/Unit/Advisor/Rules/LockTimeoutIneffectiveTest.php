<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\LockTimeoutIneffective;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Settings;

it('says so when the lock timeout can never fire', function (): void {
    $finding = (new LockTimeoutIneffective)->inspect(new Settings([
        'statement_timeout' => setting('statement_timeout', '5000'),
        'lock_timeout' => setting('lock_timeout', '5000'),
    ]));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('lock-timeout-ineffective')
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe('server');
});

it('is content when the lock timeout fires first', function (): void {
    expect((new LockTimeoutIneffective)->inspect(new Settings([
        'statement_timeout' => setting('statement_timeout', '5000'),
        'lock_timeout' => setting('lock_timeout', '1000'),
    ])))->toBeNull();
});

it('is content when statement_timeout is disabled, since there is nothing to lose to', function (): void {
    expect((new LockTimeoutIneffective)->inspect(new Settings([
        'statement_timeout' => setting('statement_timeout', '0'),
        'lock_timeout' => setting('lock_timeout', '5000'),
    ])))->toBeNull();
});

it('is content when lock_timeout is disabled, since it is not set to anything ineffective', function (): void {
    expect((new LockTimeoutIneffective)->inspect(new Settings([
        'statement_timeout' => setting('statement_timeout', '5000'),
        'lock_timeout' => setting('lock_timeout', '0'),
    ])))->toBeNull();
});

it('is content when nobody could read one of the two settings', function (): void {
    // A role without pg_read_all_settings sees these as absent rather than as an
    // error, and a rule cannot compare a value it never received.
    expect((new LockTimeoutIneffective)->inspect(new Settings([
        'statement_timeout' => setting('statement_timeout', '5000'),
    ])))->toBeNull();
});
