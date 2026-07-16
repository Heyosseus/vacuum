<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Inspections\SettingInspection;
use Heyosseus\Vacuum\Advisor\Rules\AutovacuumDisabled;
use Heyosseus\Vacuum\Advisor\Rules\BlockedSession;
use Heyosseus\Vacuum\Advisor\Rules\CacheHitRatio;
use Heyosseus\Vacuum\Advisor\Rules\DeadTuples;
use Heyosseus\Vacuum\Advisor\Rules\DuplicateIndex;
use Heyosseus\Vacuum\Advisor\Rules\IdleInTransaction;
use Heyosseus\Vacuum\Advisor\Rules\InvalidIndex;
use Heyosseus\Vacuum\Advisor\Rules\SlowStatement;
use Heyosseus\Vacuum\Advisor\Rules\StaleStatistics;
use Heyosseus\Vacuum\Advisor\Rules\TableBloat;
use Heyosseus\Vacuum\Advisor\Rules\UnusedIndex;
use Heyosseus\Vacuum\Console\StatementGuard;
use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Values\BloatEstimate;
use Heyosseus\Vacuum\Values\CacheStatistic;
use Heyosseus\Vacuum\Values\IndexDuplicate;
use Heyosseus\Vacuum\Values\IndexStatistic;
use Heyosseus\Vacuum\Values\Session;
use Heyosseus\Vacuum\Values\Statement;
use Heyosseus\Vacuum\Values\TableStatistic;
use Illuminate\Support\Facades\DB;

/**
 * The rules are inspected against a made-up table, so the drill-downs they write
 * name a table that has to exist before the server will run them. This is the
 * shape those fixtures describe: the columns and the index the findings above
 * refer to by name.
 */
beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS orders');
    DB::statement('CREATE TABLE orders (id serial PRIMARY KEY, label text, paid boolean)');
    DB::statement('CREATE INDEX orders_label_index ON orders (label)');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS orders');
});

/**
 * Every finding that offers to open a query in the console must offer one the
 * console will accept. A button that lands you on an error is worse than no button:
 * the dashboard would be handing you a statement its own guard turns away.
 */
function investigations(): array
{
    $table = new TableStatistic(
        schema: 'public', name: 'orders', liveTuples: 1_000_000, deadTuples: 900_000,
        modificationsSinceAnalyze: 900_000, xidAge: 900_000_000, mxidAge: 900_000_000,
        lastVacuum: null, lastAutovacuum: null, lastAnalyze: null, lastAutoanalyze: null,
    );

    $index = new IndexStatistic(
        schema: 'public', table: 'orders', name: 'orders_label_index', scans: 0,
        bytes: 80 * 1024 * 1024, unique: false, primary: false, valid: false, countingSince: null,
    );

    $blocked = new Session(
        pid: 4242, user: 'app', application: 'queue', state: 'active', query: 'UPDATE orders SET paid = true',
        transactionSeconds: 900, stateSeconds: 900, blockedBy: [4243],
    );

    $idle = new Session(
        pid: 4244, user: 'app', application: 'queue', state: 'idle in transaction', query: 'SELECT 1',
        transactionSeconds: 900, stateSeconds: 900, blockedBy: [],
    );

    return [
        'dead-tuples' => app(DeadTuples::class)->inspect($table),
        'stale-statistics' => app(StaleStatistics::class)->inspect($table),
        'wraparound' => app(Heyosseus\Vacuum\Advisor\Rules\Wraparound::class)->inspect($table),
        'multixact-wraparound' => app(Heyosseus\Vacuum\Advisor\Rules\MultixactWraparound::class)->inspect($table),
        'table-bloat' => app(TableBloat::class)->inspect(new BloatEstimate(
            schema: 'public', name: 'orders', fillfactor: 100,
            realBytes: 900 * 1024 * 1024, bloatBytes: 600 * 1024 * 1024,
        )),
        'unused-index' => app(UnusedIndex::class)->inspect(new IndexStatistic(
            schema: 'public', table: 'orders', name: 'orders_label_index', scans: 0,
            bytes: 80 * 1024 * 1024, unique: false, primary: false, valid: true, countingSince: null,
        )),
        'invalid-index' => app(InvalidIndex::class)->inspect($index),
        'duplicate-index' => app(DuplicateIndex::class)->inspect(new IndexDuplicate(
            schema: 'public', table: 'orders', name: 'orders_copy', bytes: 1024,
            definition: 'CREATE INDEX orders_copy ON public.orders USING btree (label)',
            duplicateOf: 'orders_label_index', constrains: false,
        )),
        'cache-hit-ratio' => app(CacheHitRatio::class)->inspect(new CacheStatistic(
            blocksHit: 100_000, blocksRead: 900_000, countingSince: null,
        )),
        'idle-in-transaction' => app(IdleInTransaction::class)->inspect($idle),
        'blocked-session' => app(BlockedSession::class)->inspect($blocked),
        'slow-statement' => app(SlowStatement::class)->inspect(new Statement(
            queryId: '-4242', sql: 'SELECT * FROM orders WHERE id = $1',
            calls: 900, totalMilliseconds: 900_000, meanMilliseconds: 1_000, rows: 900,
        )),
        'autovacuum-disabled' => (new SettingInspection(
            new Heyosseus\Vacuum\Values\Capabilities(
                serverVersion: 170_005, extensions: [], settings: ['autovacuum' => 'off'], readsAllStatistics: true,
            ),
            [new AutovacuumDisabled],
        ))->findings()[0],
    ];
}

it('offers an investigation the console will accept, for every rule', function (): void {
    foreach (investigations() as $rule => $finding) {
        expect($finding)->not->toBeNull("{$rule} did not fire")
            ->and($finding->query)->not->toBeNull("{$rule} offers no query");

        // The guard throws on anything the console would turn away, so surviving it
        // is the assertion: no rule can ship a button that lands on an error.
        app(StatementGuard::class)->check($finding->query);

        expect(true)->toBeTrue("{$rule} offers a statement the console would reject");
    }
});

it('never offers the remediation as the query, because the console cannot write', function (): void {
    foreach (investigations() as $rule => $finding) {
        expect($finding->query)->not->toBe($finding->remediation, "{$rule} would run its own fix");
    }
});

/**
 * Surviving the guard only proves the console would let the statement through. It
 * does not prove PostgreSQL will run it — a drill-down naming a column that moved
 * between majors passes every check here and still lands the reader on an error.
 * So each one is sent to the server it was written for.
 */
it('offers an investigation postgresql will actually run, for every rule', function (): void {
    foreach (investigations() as $rule => $finding) {
        $rows = app(ReadOnlyExecutor::class)->select(rtrim((string) $finding->query, "; \n"));

        expect($rows)->toBeArray("{$rule} offers a statement the server refused");
    }
})->skip(
    fn (): bool => ! statStatementsInstalled(),
    'One drill-down reads pg_stat_statements, which is not installed on this server.',
);
