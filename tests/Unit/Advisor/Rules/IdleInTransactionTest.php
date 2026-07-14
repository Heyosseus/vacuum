<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\IdleInTransaction;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Session;

function aSession(
    string $state = 'idle in transaction',
    int $stateSeconds = 600,
    int $transactionSeconds = 600,
    array $blockedBy = [],
): Session {
    return new Session(
        pid: 4_242,
        user: 'checkout',
        application: 'queue-worker',
        state: $state,
        query: 'UPDATE orders SET total = total + 1 WHERE id = 7',
        transactionSeconds: $transactionSeconds,
        stateSeconds: $stateSeconds,
        blockedBy: $blockedBy,
    );
}

beforeEach(function (): void {
    config()->set('vacuum.thresholds.idle_in_transaction_seconds', 300);
});

it('says nothing about a session that is busy working', function (): void {
    expect(app(IdleInTransaction::class)->inspect(aSession(state: 'active')))->toBeNull();
});

it('says nothing about a session holding no transaction at all', function (): void {
    expect(app(IdleInTransaction::class)->inspect(aSession(state: 'idle')))->toBeNull();
});

it('says nothing about a transaction that has only just paused', function (): void {
    expect(app(IdleInTransaction::class)->inspect(aSession(stateSeconds: 30)))->toBeNull();
});

it('reports a transaction left open and abandoned', function (): void {
    $finding = app(IdleInTransaction::class)->inspect(aSession());

    expect($finding?->rule)->toBe('idle-in-transaction')
        ->and($finding?->subject)->toBe('pid 4242')
        ->and($finding?->severity)->toBe(Severity::Warning)
        ->and($finding?->summary)->toContain('queue-worker')
        ->and($finding?->remediation)->toBe('SELECT pg_terminate_backend(4242);');
});

it('explains that one idle transaction stops vacuuming the whole database', function (): void {
    // The part nobody expects: it is not this table that suffers, it is every
    // table, because the xmin horizon does not move while the transaction lives.
    expect(app(IdleInTransaction::class)->inspect(aSession())?->impact)
        ->toContain('xmin')
        ->toContain('any table');
});

it('raises its voice at a transaction abandoned for an hour', function (): void {
    expect(app(IdleInTransaction::class)->inspect(aSession(stateSeconds: 3_700))?->severity)
        ->toBe(Severity::Critical);
});

it('counts a transaction the application left broken as still open', function (): void {
    // 'idle in transaction (aborted)' holds the snapshot exactly as firmly.
    expect(app(IdleInTransaction::class)->inspect(aSession(state: 'idle in transaction (aborted)'))?->rule)
        ->toBe('idle-in-transaction');
});
