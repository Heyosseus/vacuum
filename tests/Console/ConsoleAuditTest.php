<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Console\Console;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * A read-only console can read every row in production. Recording nothing about who
 * ran what, and when, is the gap that keeps this package out of environments where
 * somebody has to answer that question afterwards. The original design promised
 * audit logging and it was never built.
 */
beforeEach(function (): void {
    config()->set('vacuum.console.audit', true);
    config()->set('vacuum.console.audit_channel');
});

it('records every statement it runs', function (): void {
    $log = Log::spy();

    app(Console::class)->run('SELECT 1 AS n');

    $log->shouldHaveReceived('info')->once();
});

it('records what was run, how much came back, and where', function (): void {
    $log = Log::spy();

    app(Console::class)->run('SELECT 1 AS n');

    $log->shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        expect($message)->toContain('console')
            ->and($context['statement'])->toBe('SELECT 1 AS n')
            ->and($context['rows'])->toBe(1)
            ->and($context)->toHaveKey('milliseconds')
            ->and($context)->toHaveKey('connection')
            ->and($context)->toHaveKey('user');

        return true;
    });
});

it('records a statement postgresql refused, because an attempt is the thing worth recording', function (): void {
    $log = Log::spy();

    try {
        app(Console::class)->run('SELECT * FROM a_table_that_is_not_there');
    } catch (Throwable) {
        // The failure is the controller's to render; what matters here is the record.
    }

    $log->shouldHaveReceived('info')->once();
});

it('writes to the channel the application names', function (): void {
    config()->set('vacuum.console.audit_channel', 'vacuum-audit');

    $channel = Mockery::mock(LoggerInterface::class);
    $channel->shouldReceive('info')->once();

    Log::shouldReceive('channel')->with('vacuum-audit')->once()->andReturn($channel);

    app(Console::class)->run('SELECT 1 AS n');
});

it('records nothing when the application turns the audit off', function (): void {
    config()->set('vacuum.console.audit', false);

    $log = Log::spy();

    app(Console::class)->run('SELECT 1 AS n');

    $log->shouldNotHaveReceived('info');
});
