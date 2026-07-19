<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\TimeoutsUnset;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Settings;

it('warns when nothing bounds how long a session can hold a lock or a transaction', function (): void {
    $finding = (new TimeoutsUnset)->inspect(new Settings([
        'statement_timeout' => setting('statement_timeout', '0'),
        'lock_timeout' => setting('lock_timeout', '0'),
        'idle_in_transaction_session_timeout' => setting('idle_in_transaction_session_timeout', '0'),
    ]));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('timeouts-unset')
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe('server');
});

it('is content once any one of the three timeouts is set', function (): void {
    expect((new TimeoutsUnset)->inspect(new Settings([
        'statement_timeout' => setting('statement_timeout', '15000'),
        'lock_timeout' => setting('lock_timeout', '0'),
        'idle_in_transaction_session_timeout' => setting('idle_in_transaction_session_timeout', '0'),
    ])))->toBeNull();
});
