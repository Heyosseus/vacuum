<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Console\Console;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * max_rows used to be a display cap and nothing more: the whole result was already
 * in PHP's memory by the time anything was sliced off it. statement_timeout bounds
 * how long a statement runs, not how much it hands back, so a generate_series that
 * finishes in a millisecond could still take the worker down. The cap has to be
 * asked of PostgreSQL, before the rows are on the wire.
 */
function statementsSentBy(callable $work): array
{
    $sent = [];

    DB::listen(function (QueryExecuted $query) use (&$sent): void {
        $sent[] = $query->sql;
    });

    $work();

    return $sent;
}

it('asks postgresql for the cap rather than trimming the answer afterwards', function (): void {
    config()->set('vacuum.console.max_rows', 3);

    $sent = statementsSentBy(function (): void {
        app(Console::class)->run('SELECT generate_series(1, 1000000) AS n');
    });

    $select = collect($sent)->first(fn (string $sql): bool => str_contains($sql, 'generate_series'));

    // One more than the cap: enough to know the answer was cut, never the million.
    expect($select)->toContain('LIMIT 4')
        ->and($select)->toContain('vacuum_console');
});

it('shows the cap and says the answer was cut', function (): void {
    config()->set('vacuum.console.max_rows', 3);

    $result = app(Console::class)->run('SELECT generate_series(1, 1000000) AS n');

    expect($result->rows)->toHaveCount(3)
        ->and($result->capped)->toBeTrue();
});

it('does not call a result capped when the whole answer fits', function (): void {
    config()->set('vacuum.console.max_rows', 10);

    $result = app(Console::class)->run('SELECT generate_series(1, 3) AS n');

    expect($result->rows)->toHaveCount(3)
        ->and($result->capped)->toBeFalse();
});

it('does not call a result capped when it lands exactly on the cap', function (): void {
    // The fence post: three rows and a cap of three is a complete answer, not a cut
    // one. Fetching one extra row is what lets it tell the difference.
    config()->set('vacuum.console.max_rows', 3);

    $result = app(Console::class)->run('SELECT generate_series(1, 3) AS n');

    expect($result->rows)->toHaveCount(3)
        ->and($result->capped)->toBeFalse();
});

/**
 * EXPLAIN and SHOW are not subqueries and PostgreSQL will not let them be wrapped
 * in one. They are also bounded by what they are: a plan, and a setting. Wrapping
 * everything blindly would break the console's most useful statement.
 */
it('still runs the statements that cannot be wrapped in a subquery', function (): void {
    $plan = app(Console::class)->run('EXPLAIN SELECT 1');
    $setting = app(Console::class)->run('SHOW statement_timeout');

    expect($plan->rows)->not->toBeEmpty()
        ->and($setting->rows)->not->toBeEmpty();
});

it('caps a WITH, a TABLE and a VALUES the same way it caps a SELECT', function (): void {
    config()->set('vacuum.console.max_rows', 2);

    $with = app(Console::class)->run('WITH n AS (SELECT generate_series(1, 100) AS i) SELECT * FROM n');
    $values = app(Console::class)->run('VALUES (1), (2), (3), (4)');

    expect($with->rows)->toHaveCount(2)
        ->and($with->capped)->toBeTrue()
        ->and($values->rows)->toHaveCount(2)
        ->and($values->capped)->toBeTrue();
});

it('keeps the trailing semicolon everybody types from breaking the wrap', function (): void {
    config()->set('vacuum.console.max_rows', 2);

    // A semicolon inside a subquery is a syntax error, so it has to come off before
    // the statement is wrapped rather than after.
    $result = app(Console::class)->run('SELECT generate_series(1, 10) AS n;');

    expect($result->rows)->toHaveCount(2)
        ->and($result->capped)->toBeTrue();
});

/**
 * The row cap bounds how many results come back and says nothing about how wide
 * each one is. Three hundred rows of a megabyte each sits well inside a cap of
 * five hundred and is three hundred megabytes into a web worker, produced in
 * under a second -- and statement_timeout cannot stop it, because the timeout
 * bounds execution rather than transfer. So a byte budget rides alongside, kept
 * as a running total the server filters on, which is the same shape as the row
 * cap and works for the same reason: the rows past it are never sent.
 */
it('stops sending rows once they have cost more than the byte budget', function (): void {
    config()->set('vacuum.console.max_rows', 500);
    config()->set('vacuum.console.max_bytes', 50_000);

    // Twenty rows of ten kilobytes: 200 kB in total, four times the budget, and
    // nowhere near the row cap.
    $result = app(Console::class)->run("SELECT repeat('x', 10000) AS wide FROM generate_series(1, 20)");

    expect($result->rows)->not->toBeEmpty()
        ->and(count($result->rows))->toBeLessThan(20)
        ->and($result->capped)->toBeTrue();
});

it('keeps the row that crosses the budget, so a cut answer is never an empty one', function (): void {
    // Dropping every row over the budget would make one enormous row indistinguishable
    // from no rows at all, and "0 rows" is a worse lie than "here is the first one".
    config()->set('vacuum.console.max_rows', 500);
    config()->set('vacuum.console.max_bytes', 100);

    $result = app(Console::class)->run("SELECT repeat('x', 10000) AS wide FROM generate_series(1, 5)");

    expect($result->rows)->toHaveCount(1)
        ->and($result->capped)->toBeTrue();
});

it('leaves a result inside both budgets alone', function (): void {
    config()->set('vacuum.console.max_rows', 500);
    config()->set('vacuum.console.max_bytes', 8 * 1024 * 1024);

    $result = app(Console::class)->run('SELECT generate_series(1, 3) AS n');

    expect($result->rows)->toHaveCount(3)
        ->and($result->capped)->toBeFalse();
});

it('does not leak its own bookkeeping columns into the result', function (): void {
    // The wrapper adds a running byte total and a row count to decide whether the
    // answer was cut. What the reader asked for is what they get back.
    $result = app(Console::class)->run('SELECT 1 AS n');

    expect($result->columns)->toBe(['n'])
        ->and(array_keys($result->rows[0]))->toBe(['n']);
});

it('runs the statement the guard approved rather than the one that was typed', function (): void {
    // The structural half of the console fix: a comment is stripped once, by the
    // guard, and the string it returns is the string that reaches PostgreSQL.
    // Checking one and executing the other is what let a payload hide inside a
    // literal.
    $sent = statementsSentBy(function (): void {
        app(Console::class)->run("SELECT 1 AS n -- a trailing comment\n");
    });

    $select = collect($sent)->first(fn (string $sql): bool => str_contains($sql, 'AS n'));

    expect($select)->not->toContain('a trailing comment');
});
