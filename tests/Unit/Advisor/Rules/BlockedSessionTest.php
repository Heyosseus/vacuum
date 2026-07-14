<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\BlockedSession;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Session;

function waiting(array $blockedBy, int $stateSeconds = 10): Session
{
    return new Session(
        pid: 9_001,
        user: 'checkout',
        application: 'web',
        state: 'active',
        query: 'UPDATE orders SET total = 0 WHERE id = 7',
        transactionSeconds: $stateSeconds,
        stateSeconds: $stateSeconds,
        blockedBy: $blockedBy,
    );
}

beforeEach(function (): void {
    config()->set('vacuum.thresholds.long_running_query_seconds', 60);
});

it('says nothing about a session nothing is holding up', function (): void {
    expect(app(BlockedSession::class)->inspect(waiting([])))->toBeNull();
});

it('reports a session waiting on a lock somebody else holds', function (): void {
    $finding = app(BlockedSession::class)->inspect(waiting([4_242]));

    expect($finding?->rule)->toBe('blocked-session')
        ->and($finding?->subject)->toBe('pid 9001')
        ->and($finding?->severity)->toBe(Severity::Warning)
        ->and($finding?->summary)->toContain('4242')
        ->and($finding?->remediation)->toBe('SELECT pg_cancel_backend(4242);');
});

it('names every session in the way, not merely the first', function (): void {
    expect(app(BlockedSession::class)->inspect(waiting([4_242, 4_243]))?->summary)
        ->toContain('4242')
        ->toContain('4243');
});

it('raises its voice at a session that has been waiting too long', function (): void {
    expect(app(BlockedSession::class)->inspect(waiting([4_242], stateSeconds: 90))?->severity)
        ->toBe(Severity::Critical);
});

it('cancels the query rather than killing the connection', function (): void {
    // pg_cancel_backend stops the statement and leaves the session alive to roll
    // back cleanly. pg_terminate_backend takes the whole connection down with it.
    expect(app(BlockedSession::class)->inspect(waiting([4_242]))?->impact)
        ->toContain('queue');
});
