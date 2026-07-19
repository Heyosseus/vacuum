<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Lessons\NPlusOne;
use Heyosseus\Vacuum\Queries\Statements;
use Heyosseus\Vacuum\Values\Capabilities;
use Heyosseus\Vacuum\Values\Statement;
use Illuminate\Support\Facades\DB;

/**
 * A table this lesson can run real lookups against, so observe() has an actual
 * statement to rank rather than only whatever happens to already be in
 * pg_stat_statements on a shared connection.
 */
beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_npo_customers');
    DB::statement('CREATE TABLE learn_npo_customers (id serial PRIMARY KEY, name text)');
    DB::insert("INSERT INTO learn_npo_customers (name) SELECT 'customer ' || i FROM generate_series(1, 3) i");
    DB::statement('SELECT pg_stat_statements_reset()');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_npo_customers');
});

function nPlusOneLesson(): NPlusOne
{
    return new NPlusOne(app(Statements::class), app(Capabilities::class));
}

function extensionTracksStatements(): bool
{
    return DB::table('pg_extension')->where('extname', 'pg_stat_statements')->exists();
}

/**
 * Builds a Statement without a live database, since Statements is a final
 * readonly class wrapping a read-only executor and cannot be mocked -- the
 * same reason {@see Heyosseus\Vacuum\Learn\Lessons\Fillfactor::fork()} takes
 * already-fetched values rather than issuing its own query.
 */
function builtStatement(string $sql, int $calls, float $meanMilliseconds = 0.05): Statement
{
    return new Statement(
        queryId: 'queryid-'.md5($sql),
        sql: $sql,
        calls: $calls,
        totalMilliseconds: $meanMilliseconds * $calls,
        meanMilliseconds: $meanMilliseconds,
        rows: $calls,
    );
}

it('names its slug, title, tier, hook and prerequisite', function (): void {
    $lesson = nPlusOneLesson();

    expect($lesson->slug())->toBe('n-plus-one')
        ->and($lesson->title())->toBe('What N+1 looks like from the database')
        ->and($lesson->tier())->toBe(Heyosseus\Vacuum\Learn\Tier::Eloquent)
        ->and($lesson->after())->toBe('unindexed-foreign-keys')
        ->and($lesson->hook())->not->toBeEmpty();
});

it('hands the reader a runnable statement for band three', function (): void {
    $sql = nPlusOneLesson()->tryIt();

    expect($sql)->toBeString()
        ->and($sql)->toContain('pg_stat_statements')
        ->and($sql)->toContain('order by calls desc');
});

it('names the most-called statement, its call count and its mean time', function (): void {
    DB::select('SELECT id FROM learn_npo_customers WHERE id = 1');
    DB::select('SELECT id FROM learn_npo_customers WHERE id = 1');
    DB::select('SELECT id FROM learn_npo_customers WHERE id = 1');
    flushStatistics();

    $observation = nPlusOneLesson()->observe();

    expect($observation->isEmpty())->toBeFalse()
        ->and($observation->columns)->toBe(['statement', 'calls', 'mean time', 'total time'])
        ->and($observation->headline)->not->toBeEmpty();

    $statements = implode(' ', array_map(static fn (array $row): string => $row[0], $observation->rows));

    expect($statements)->toContain('learn_npo_customers');
})->skip(fn (): bool => ! extensionTracksStatements(), 'pg_stat_statements is not installed on this server.');

/**
 * Proves the ranking is by call count and not by mean time -- the whole point
 * of this lesson. The connection's own PDO bookkeeping (DEALLOCATE, driven by
 * how many distinct statements got prepared) adds noise that can legitimately
 * outrank both of these on a shared test database, so this does not assert
 * our statement lands first overall. It asserts the one thing the lesson
 * promises: a statement called four times ranks ahead of one that is far
 * slower per call but was only called once.
 */
it('ranks by call count rather than by mean time', function (): void {
    // Both statements are run enough times to sit inside the ten rows the lesson
    // renders. A single slow call was the honest shape of the claim but not a
    // stable test: between the reset and the assertion the framework issues its
    // own statements, and on a full-suite run enough of those outrank a
    // one-call entry to push it off the page. Raising both counts keeps the
    // comparison the lesson actually promises -- 40 fast calls ahead of 12 slow
    // ones -- while making the window deterministic.
    for ($i = 0; $i < 40; $i++) {
        DB::select('SELECT id FROM learn_npo_customers WHERE id = 2');
    }

    for ($i = 0; $i < 12; $i++) {
        DB::select('SELECT pg_sleep(0.01)');
    }

    flushStatistics();

    $observation = nPlusOneLesson()->observe();

    $rowOf = function (string $needle) use ($observation): ?int {
        foreach ($observation->rows as $index => $row) {
            if (str_contains($row[0], $needle)) {
                return $index;
            }
        }

        return null;
    };

    $customerLookupIndex = $rowOf('learn_npo_customers');
    $sleepIndex = $rowOf('pg_sleep');

    expect($customerLookupIndex)->not->toBeNull()
        ->and($observation->rows[$customerLookupIndex][1])->toBe('40')
        ->and($sleepIndex)->not->toBeNull()
        ->and($customerLookupIndex)->toBeLessThan($sleepIndex);
})->skip(fn (): bool => ! extensionTracksStatements(), 'pg_stat_statements is not installed on this server.');

it('says so plainly, by name, when pg_stat_statements is not tracking anything', function (): void {
    app()->instance(Capabilities::class, new Capabilities(
        serverVersion: 170_005,
        extensions: [],
        settings: [],
        readsAllStatistics: true,
    ));

    $observation = nPlusOneLesson()->observe();

    expect($observation->isEmpty())->toBeTrue()
        ->and($observation->rows)->toBe([])
        ->and($observation->note)->not->toBeNull()
        ->and($observation->note)->toContain('pg_stat_statements');
});

it('says so when the extension is tracking but this database has nothing recorded', function (): void {
    // busiest() cannot be made to return [] deterministically on a live, shared
    // connection -- the driver's own DEALLOCATE bookkeeping is itself a
    // statement pg_stat_statements records -- so this reaches the private
    // rendering half of observe() directly with an empty list, via reflection,
    // rather than depending on a live query this package cannot control.
    $lesson = nPlusOneLesson();
    $method = new ReflectionMethod($lesson, 'toObservation');

    $observation = $method->invoke($lesson, []);

    expect($observation->isEmpty())->toBeTrue()
        ->and($observation->note)->not->toBeNull();
});

it('formats the busiest statement into a headline and rows without touching a live database', function (): void {
    $lesson = nPlusOneLesson();
    $method = new ReflectionMethod($lesson, 'toObservation');

    $observation = $method->invoke($lesson, [
        builtStatement('select * from "orders" where "id" = $1', calls: 250_000, meanMilliseconds: 0.08),
        builtStatement('select pg_sleep(1)', calls: 1, meanMilliseconds: 1_000.0),
    ]);

    expect($observation->isEmpty())->toBeFalse()
        ->and($observation->headline)->toContain('250,000')
        ->and($observation->headline)->toContain('0.080')
        ->and($observation->rows[0][0])->toContain('orders')
        ->and($observation->rows[0][1])->toBe('250,000');
});

it('delegates tree() to fork() using its own live data', function (): void {
    $tree = nPlusOneLesson()->tree();

    expect($tree->question)->toBe('You have a statement running a very large number of times. Is it N+1?')
        ->and($tree->branches)->toHaveCount(2);
});

it('sends a single-row key lookup called relentlessly down the loop branch, with no SQL fix', function (): void {
    $lesson = nPlusOneLesson();

    $lookup = builtStatement('select * from "customers" where "id" = $1', calls: 50_000);

    $tree = $lesson->fork([$lookup]);
    [$loop, $hot] = $tree->branches;

    expect($loop->isTaken())->toBeTrue()
        ->and($loop->landed[0])->toContain('customers')
        ->and($loop->fix)->toBeNull()
        ->and($loop->outcome)->toContain("Order::with('customer')->get()")
        ->and($hot->isTaken())->toBeFalse();
});

it('sends a foreign-key lookup called relentlessly down the loop branch too', function (): void {
    $lesson = nPlusOneLesson();

    $lookup = builtStatement('select * from "orders" where "customer_id" = $1', calls: 20_000);

    $tree = $lesson->fork([$lookup]);
    [$loop, $hot] = $tree->branches;

    expect($loop->isTaken())->toBeTrue()
        ->and($hot->isTaken())->toBeFalse();
});

it('sends a relentlessly-called statement that is not a single-row lookup down the hot-path branch', function (): void {
    $lesson = nPlusOneLesson();

    $hotStatement = builtStatement('select count(*) from "orders" where "status" = $1', calls: 20_000);

    $tree = $lesson->fork([$hotStatement]);
    [$loop, $hot] = $tree->branches;

    expect($loop->isTaken())->toBeFalse()
        ->and($hot->isTaken())->toBeTrue()
        ->and($hot->landed[0])->toContain('orders')
        ->and($hot->fix)->toBeNull();
});

it('leaves a statement below the call threshold off the tree entirely', function (): void {
    $lesson = nPlusOneLesson();

    $rare = builtStatement('select * from "customers" where "id" = $1', calls: 3);

    $tree = $lesson->fork([$rare]);
    [$loop, $hot] = $tree->branches;

    expect($loop->isTaken())->toBeFalse()
        ->and($hot->isTaken())->toBeFalse();
});

it('does not treat a statement with one placeholder but no where-equals-key shape as a lookup', function (): void {
    $lesson = nPlusOneLesson();

    // One placeholder, but it is bound in a SET clause, not a WHERE equality --
    // the shape the heuristic looks for is absent entirely, not merely on the
    // wrong column.
    $update = builtStatement('update "orders" set "status" = $1', calls: 20_000);

    $tree = $lesson->fork([$update]);
    [$loop, $hot] = $tree->branches;

    expect($loop->isTaken())->toBeFalse()
        ->and($hot->isTaken())->toBeTrue();
});

it('truncates a long statement with an ellipsis when it is shown to the reader', function (): void {
    $lesson = nPlusOneLesson();

    $long = builtStatement(
        'select * from "a_very_long_table_name_used_only_to_push_this_statement_well_past_the_preview_length" where "id" = $1',
        calls: 20_000,
    );

    $tree = $lesson->fork([$long]);
    [$loop] = $tree->branches;

    expect($loop->landed[0])->toContain('…')
        ->and(mb_strlen($loop->landed[0]))->toBeLessThan(mb_strlen($long->sql));
});

it('does not treat a multi-condition lookup as a single-row key lookup', function (): void {
    $lesson = nPlusOneLesson();

    $twoConditions = builtStatement(
        'select * from "orders" where "customer_id" = $1 and "status" = $2',
        calls: 20_000,
    );

    $tree = $lesson->fork([$twoConditions]);
    [$loop, $hot] = $tree->branches;

    expect($loop->isTaken())->toBeFalse()
        ->and($hot->isTaken())->toBeTrue();
});
