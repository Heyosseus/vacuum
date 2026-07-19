<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\PendingRestart;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Settings;

it('says so when a setting was changed and the server never picked it up', function (): void {
    $finding = (new PendingRestart)->inspect(new Settings([
        'shared_buffers' => setting('shared_buffers', '32768', context: 'postmaster', pendingRestart: true),
        'work_mem' => setting('work_mem', '4096'),
    ]));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('pending-restart')
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe('server');
});

it('is content when nothing is waiting on a restart', function (): void {
    expect((new PendingRestart)->inspect(new Settings([
        'work_mem' => setting('work_mem', '4096'),
    ])))->toBeNull();
});
