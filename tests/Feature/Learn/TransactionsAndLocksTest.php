<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Lessons\TransactionsAndLocks;
use Heyosseus\Vacuum\Learn\Tier;
use Heyosseus\Vacuum\Queries\ServerSettings;
use Heyosseus\Vacuum\Queries\Sessions;
use Heyosseus\Vacuum\Values\Session;
use Heyosseus\Vacuum\Values\Setting;
use Heyosseus\Vacuum\Values\Settings;

/**
 * No fixture opens a real transaction and leaves it hanging -- that would be the
 * exact mistake this lesson warns against, and would leave a connection pinning
 * the horizon for every other test in a shared-database suite run. Everything
 * fork() needs is built instead.
 */
afterEach(function (): void {
    // Nothing persistent is created by this file, but every other Learn test
    // cleans up unconditionally in afterEach and this one keeps the pattern so
    // a future fixture added here does not have to invent its own convention.
});

function transactionsLesson(): TransactionsAndLocks
{
    return new TransactionsAndLocks(app(Sessions::class), app(ServerSettings::class));
}

/**
 * A Session built for a test, without the ceremony of naming every field a
 * given test does not care about. Named openTransactionSession() rather than
 * aSession() -- IdleInTransactionTest.php already declares aSession() with a
 * different signature, and a global function can only be declared once across
 * a full-suite run.
 */
function openTransactionSession(
    int $pid = 4_242,
    string $state = 'idle in transaction',
    int $transactionSeconds = 600,
    string $query = 'UPDATE orders SET total = total + 1 WHERE id = 7',
): Session {
    return new Session(
        pid: $pid,
        user: 'checkout',
        application: 'queue-worker',
        state: $state,
        query: $query,
        transactionSeconds: $transactionSeconds,
        stateSeconds: $transactionSeconds,
        blockedBy: [],
    );
}

/**
 * A pg_settings row for idle_in_transaction_session_timeout, wrapped in Settings
 * the way ServerSettings::read() would hand it back.
 */
function timeoutSettings(?string $value, ?string $unit = 's'): Settings
{
    if ($value === null) {
        return new Settings([]);
    }

    return new Settings([
        'idle_in_transaction_session_timeout' => new Setting(
            name: 'idle_in_transaction_session_timeout',
            value: $value,
            resetValue: $value,
            unit: $unit,
            context: 'sighup',
            source: 'configuration file',
            bootValue: '0',
            pendingRestart: false,
        ),
    ]);
}

it('describes itself for the curriculum', function (): void {
    $lesson = transactionsLesson();

    expect($lesson->slug())->toBe('transactions-and-locks')
        ->and($lesson->title())->toBe('The transaction you left open')
        ->and($lesson->tier())->toBe(Tier::Eloquent)
        ->and($lesson->after())->toBeNull()
        ->and($lesson->hook())->not->toBeEmpty();
});

it('hands the reader a runnable statement for band three', function (): void {
    $sql = transactionsLesson()->tryIt();

    expect($sql)->toBeString()
        ->and($sql)->toContain('pg_stat_activity')
        ->and($sql)->toContain('xact_start');
});

it('produces a headline on a live connection', function (): void {
    $observation = transactionsLesson()->observe();

    expect($observation->headline)->not->toBeEmpty();
});

it('says so as good news when nothing on this connection is holding a transaction open', function (): void {
    // pg_stat_activity is read outside any transaction Vacuum itself opens, and
    // ReadOnlyExecutor does not wrap reads in one, so the live database this test
    // runs against ordinarily has nothing of its own to show here. When some other
    // connection sharing the test database happens to be mid-transaction, this
    // assertion is skipped rather than made to fail on a false negative.
    $observation = transactionsLesson()->observe();

    if (! $observation->isEmpty()) {
        expect($observation->rows)->not->toBeEmpty();

        return;
    }

    expect($observation->headline)->toContain('No session')
        ->and($observation->note)->not->toBeNull()
        ->and($observation->note)->toContain('live snapshot');
});

it('delegates tree() to fork() using its own live data', function (): void {
    $tree = transactionsLesson()->tree();

    expect($tree->question)->not->toBeEmpty()
        ->and($tree->branches)->toHaveCount(3);
});

it('sends a long-idle transaction to the pinned-horizon branch', function (): void {
    $idle = openTransactionSession(transactionSeconds: 900);

    $tree = transactionsLesson()->fork([$idle], timeoutSettings('60', 's'));

    expect($tree->branches[0]->landed)->toBe(['pid 4242, idle 15.0 min'])
        ->and($tree->branches[0]->fix)->toBeNull()
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('does not send a freshly idle transaction under the threshold to the pinned-horizon branch', function (): void {
    $fresh = openTransactionSession(transactionSeconds: 5);

    $tree = transactionsLesson()->fork([$fresh], timeoutSettings('60', 's'));

    expect($tree->branches[0]->landed)->toBe([]);
});

it('does not send an active (not idle) transaction to the pinned-horizon branch', function (): void {
    $active = openTransactionSession(state: 'active', transactionSeconds: 900);

    $tree = transactionsLesson()->fork([$active], timeoutSettings('60', 's'));

    expect($tree->branches[0]->landed)->toBe([]);
});

it('sends an unset timeout to the missing-backstop branch when nothing is idle', function (): void {
    $tree = transactionsLesson()->fork([], timeoutSettings(null));

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->not->toBeEmpty()
        ->and($tree->branches[1]->fix)->toBe("alter system set idle_in_transaction_session_timeout = '60s';")
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('treats a zero timeout the same as an unset one', function (): void {
    $tree = transactionsLesson()->fork([], timeoutSettings('0', null));

    expect($tree->branches[1]->landed)->not->toBeEmpty()
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('sends a configured timeout to the good-configuration branch when nothing is idle', function (): void {
    $tree = transactionsLesson()->fork([], timeoutSettings('60', 's'));

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe(['idle_in_transaction_session_timeout = 60s']);
});

it('sends nothing to the settings branches while a session is still idle in transaction, even under threshold', function (): void {
    $fresh = openTransactionSession(transactionSeconds: 5);

    $tree = transactionsLesson()->fork([$fresh], timeoutSettings(null));

    expect($tree->branches[0]->landed)->toBe([])
        ->and($tree->branches[1]->landed)->toBe([])
        ->and($tree->branches[2]->landed)->toBe([]);
});

it('headlines an idle-in-transaction session as pinning the horizon', function (): void {
    $idle = openTransactionSession(pid: 4_242, transactionSeconds: 600);

    $observation = transactionsLesson()->report([$idle]);

    expect($observation->headline)->toContain('queue-worker')
        ->and($observation->headline)->toContain('pid 4242')
        ->and($observation->headline)->toContain('idle inside an open transaction')
        ->and($observation->headline)->toContain('10.0')
        ->and($observation->columns)->toBe(['pid', 'state', 'duration', 'query'])
        ->and($observation->rows)->toBe([[
            '4242',
            'idle in transaction',
            '10.0 min',
            'UPDATE orders SET total = total + 1 WHERE id = 7',
        ]]);
});

it('headlines an active transaction as still working but still holding the horizon', function (): void {
    $active = openTransactionSession(pid: 99, state: 'active', transactionSeconds: 120);

    $observation = transactionsLesson()->report([$active]);

    expect($observation->headline)->toContain('pid 99')
        ->and($observation->headline)->toContain('active transaction')
        ->and($observation->headline)->not->toContain('idle inside');
});

it('truncates a query longer than the preview length with an ellipsis in the row', function (): void {
    $longQuery = 'UPDATE orders SET total = total + 1 WHERE id in ('.implode(',', range(1, 30)).')';
    $session = openTransactionSession(query: $longQuery);

    $observation = transactionsLesson()->report([$session]);

    $row = $observation->rows[0];

    expect(mb_strlen($longQuery))->toBeGreaterThan(80)
        ->and($row[3])->toEndWith('…')
        ->and(mb_strlen($row[3]))->toBe(81)
        ->and($row[3])->toBe(mb_substr($longQuery, 0, 80).'…');
});

it('falls back to the user when application is empty for the headline "who"', function (): void {
    $session = new Session(
        pid: 555,
        user: 'checkout',
        application: '',
        state: 'idle in transaction',
        query: 'SELECT 1',
        transactionSeconds: 60,
        stateSeconds: 60,
        blockedBy: [],
    );

    $observation = transactionsLesson()->report([$session]);

    expect($observation->headline)->toContain('`checkout`')
        ->and($observation->headline)->not->toContain('``');
});

it('renders all three branches when nothing landed on any of them', function (): void {
    $tree = transactionsLesson()->fork([], timeoutSettings('60', 's'));

    // Nothing landed here only because timeoutSettings('60', 's') sends this
    // particular call to branch three; the assertion that matters is that every
    // branch still carries a non-empty condition regardless of what landed.
    expect($tree->branches)->toHaveCount(3)
        ->and($tree->branches[0]->condition)->not->toBeEmpty()
        ->and($tree->branches[1]->condition)->not->toBeEmpty()
        ->and($tree->branches[2]->condition)->not->toBeEmpty();
});
