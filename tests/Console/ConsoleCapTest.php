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
