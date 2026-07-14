<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Database\ReadOnlyExecutor;
use Heyosseus\Vacuum\Exceptions\NestedTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('CREATE TABLE IF NOT EXISTS widgets (id serial PRIMARY KEY, name text)');
    DB::statement('TRUNCATE widgets');
    DB::insert("INSERT INTO widgets (name) VALUES ('anvil'), ('bolt')");
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS widgets');
});

it('returns the rows a select produces', function (): void {
    $rows = app(ReadOnlyExecutor::class)->select('SELECT name FROM widgets ORDER BY name');

    expect($rows)->toBe([
        ['name' => 'anvil'],
        ['name' => 'bolt'],
    ]);
});

it('binds parameters rather than interpolating them', function (): void {
    $rows = app(ReadOnlyExecutor::class)->select('SELECT name FROM widgets WHERE name = ?', ['bolt']);

    expect($rows)->toBe([['name' => 'bolt']]);
});

it('runs its statement inside a read-only transaction', function (): void {
    $rows = app(ReadOnlyExecutor::class)->select("SELECT current_setting('transaction_read_only') AS read_only");

    expect($rows)->toBe([['read_only' => 'on']]);
});

it('lets postgresql itself reject a statement that writes', function (): void {
    expect(fn (): array => app(ReadOnlyExecutor::class)->select("INSERT INTO widgets (name) VALUES ('cog')"))
        ->toThrow(QueryException::class, 'read-only transaction');

    expect(DB::table('widgets')->count())->toBe(2);
});

it('aborts a statement that outruns its timeout', function (): void {
    expect(fn (): array => app(ReadOnlyExecutor::class)->select('SELECT pg_sleep(5)', timeoutMilliseconds: 250))
        ->toThrow(QueryException::class, 'statement timeout');
});

it('leaves no transaction open once a statement has run', function (): void {
    app(ReadOnlyExecutor::class)->select('SELECT 1');

    // The connection is shared. Had the read-only transaction been left open,
    // this write would fail rather than succeed.
    DB::insert("INSERT INTO widgets (name) VALUES ('cog')");

    expect(DB::table('widgets')->count())->toBe(3);
});

it('leaves no transaction open once a statement has failed', function (): void {
    expect(fn (): array => app(ReadOnlyExecutor::class)->select('SELECT * FROM nonexistent_table'))
        ->toThrow(QueryException::class);

    DB::insert("INSERT INTO widgets (name) VALUES ('cog')");

    expect(DB::table('widgets')->count())->toBe(3);
});

it('confines the timeout to its own transaction', function (): void {
    app(ReadOnlyExecutor::class)->select('SELECT 1', timeoutMilliseconds: 250);

    expect(DB::selectOne('SHOW statement_timeout')->statement_timeout)->toBe('0');
});

it('does not let a rejected write be retried onto a fresh connection', function (): void {
    // Laravel's LostConnectionDetector matches PostgreSQL's read-only violation
    // ("SQLSTATE[25006]: Read only sql transaction: 7") as a lost connection, so
    // Connection::run() would reconnect and re-run the statement outside the
    // transaction -- committing the write we just refused. It only skips that
    // retry while a Laravel-tracked transaction is open, which is why the
    // executor must use beginTransaction() rather than raw BEGIN sql.
    expect(fn (): array => app(ReadOnlyExecutor::class)->select("INSERT INTO widgets (name) VALUES ('cog')"))
        ->toThrow(QueryException::class);

    expect(DB::table('widgets')->whereName('cog')->exists())->toBeFalse();
});

it('refuses to run while a transaction is already open on the connection', function (): void {
    DB::beginTransaction();

    try {
        expect(fn (): array => app(ReadOnlyExecutor::class)->select('SELECT 1'))
            ->toThrow(NestedTransaction::class);
    } finally {
        DB::rollBack();
    }
});
