<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Values\Settings;

it('says what actually has to happen for a change to take effect', function (): void {
    expect(setting('work_mem', '4096', context: 'user')->changeRequires())->toBe('session')
        ->and(setting('log_lock_waits', 'off', context: 'superuser')->changeRequires())->toBe('session')
        ->and(setting('max_wal_size', '1024', context: 'sighup')->changeRequires())->toBe('reload')
        ->and(setting('shared_buffers', '16384', context: 'postmaster')->changeRequires())->toBe('restart')
        ->and(setting('block_size', '8192', context: 'internal')->changeRequires())->toBe('rebuild')
        ->and(setting('ssl', 'on', context: 'backend')->changeRequires())->toBe('reconnect')
        ->and(setting('log_connections', 'on', context: 'superuser-backend')->changeRequires())->toBe('reconnect');
});

it('knows whether a setting was ever changed from what it shipped as', function (): void {
    expect(setting('work_mem', '4096', bootValue: '4096')->isDefault())->toBeTrue()
        ->and(setting('work_mem', '65536', bootValue: '4096')->isDefault())->toBeFalse();
});

it('reads a setting by name, and answers null rather than guessing', function (): void {
    $settings = new Settings(['work_mem' => setting('work_mem', '4096')]);

    expect($settings->value('work_mem'))->toBe('4096')
        ->and($settings->integer('work_mem'))->toBe(4096)
        ->and($settings->value('nothing_read_this'))->toBeNull()
        ->and($settings->integer('nothing_read_this'))->toBeNull()
        ->and($settings->get('nothing_read_this'))->toBeNull();
});

it('treats a setting nobody could read as off rather than as on', function (): void {
    // A rule that cannot prove a safety net is on must not assume it is.
    $settings = new Settings(['log_lock_waits' => setting('log_lock_waits', 'off')]);

    expect($settings->isOff('log_lock_waits'))->toBeTrue()
        ->and($settings->isOff('never_probed'))->toBeTrue();
});

it('lists the settings whose change never took effect', function (): void {
    $settings = new Settings([
        'shared_buffers' => setting('shared_buffers', '16384', context: 'postmaster', pendingRestart: true),
        'work_mem' => setting('work_mem', '4096'),
    ]);

    expect(array_keys($settings->pendingRestart()))->toBe(['shared_buffers'])
        ->and($settings->all())->toHaveCount(2);
});
