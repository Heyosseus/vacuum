<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Inspections\StatementInspection;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('reports a statement the database actually found slow', function (): void {
    config()->set('vacuum.thresholds.slow_query_milliseconds', 100);

    DB::statement('SELECT pg_stat_statements_reset()');
    DB::select('SELECT pg_sleep(0.2)');

    $findings = app(StatementInspection::class)->findings();

    expect(array_column($findings, 'rule'))->toContain('slow-statement')
        ->and(array_column($findings, 'evidence'))->toContain('SELECT pg_sleep($1)');
})->skip(
    fn (): bool => ! extensionInstalled(),
    'pg_stat_statements is not installed on this server.',
);

it('explains how to turn query analytics on rather than pretending there is nothing to see', function (): void {
    // Without the extension the panel has no data at all, and an empty panel reads
    // as "no slow queries" rather than as "nobody is looking".
    app()->instance(Capabilities::class, new Capabilities(
        serverVersion: 170_005,
        extensions: ['plpgsql'],
        settings: ['track_counts' => 'on', 'shared_preload_libraries' => 'pg_stat_statements'],
        readsAllStatistics: true,
    ));

    $findings = app(StatementInspection::class)->findings();

    expect(array_column($findings, 'rule'))->toBe(['extension-missing'])
        ->and($findings[0]->remediation)->toBe('CREATE EXTENSION IF NOT EXISTS pg_stat_statements;')
        ->and($findings[0]->impact)->toContain('shared_preload_libraries');
});

it('does not trust CREATE EXTENSION alone when the library was never preloaded', function (): void {
    // CREATE EXTENSION succeeds on a server that never preloaded the library,
    // and the view it leaves behind throws on the first read. The guidance is
    // the same finding: its text already explains the restart.
    app()->instance(Capabilities::class, new Capabilities(
        serverVersion: 170_005,
        extensions: ['pg_stat_statements'],
        settings: ['shared_preload_libraries' => 'auto_explain'],
        readsAllStatistics: true,
    ));

    $findings = app(StatementInspection::class)->findings();

    expect(array_column($findings, 'rule'))->toBe(['extension-missing'])
        ->and($findings[0]->impact)->toContain('shared_preload_libraries');
});

it('degrades to the guidance when the server refuses the view the probe promised', function (): void {
    // A role without pg_read_all_settings cannot see the preload list, so the
    // capabilities give the extension the benefit of the doubt -- and on a
    // half-installed server the view then refuses the read. The SQLSTATE the
    // server raises for "not preloaded" is caught and answered with the same
    // finding rather than a 500.
    capabilitiesThatPromiseStatements();
    connectionThatRefuses('55000');

    $findings = app(StatementInspection::class)->findings();

    expect(array_column($findings, 'rule'))->toBe(['extension-missing']);
});

it('does not swallow a failure that is not about preloading', function (): void {
    capabilitiesThatPromiseStatements();
    connectionThatRefuses('42501');

    app(StatementInspection::class)->findings();
})->throws(QueryException::class);

function extensionInstalled(): bool
{
    return DB::table('pg_extension')->where('extname', 'pg_stat_statements')->exists();
}

/**
 * The extension created, and the preload list hidden the way it is from any
 * role without pg_read_all_settings: the shape that earns the benefit of the
 * doubt, and the shape a half-installed server betrays.
 */
function capabilitiesThatPromiseStatements(): void
{
    app()->instance(Capabilities::class, new Capabilities(
        serverVersion: 170_005,
        extensions: ['pg_stat_statements'],
        settings: ['track_counts' => 'on'],
        readsAllStatistics: true,
    ));
}

/**
 * Swap the pgsql connection for one whose every SELECT raises the given
 * SQLSTATE, the way a server that never preloaded the library raises 55000
 * against a view CREATE EXTENSION happily left behind.
 */
function connectionThatRefuses(string $sqlstate): void
{
    $connection = new class(new PDO('sqlite::memory:'), 'vacuum_test', '', ['name' => 'pgsql', 'driver' => 'pgsql']) extends Connection
    {
        public string $sqlstate = '55000';

        public function statement($query, $bindings = []): bool
        {
            return true;
        }

        public function select($query, $bindings = [], $useReadPdo = true): array
        {
            $refused = new PDOException(
                "SQLSTATE[{$this->sqlstate}]: pg_stat_statements must be loaded via \"shared_preload_libraries\"",
            );
            $refused->errorInfo = [$this->sqlstate, 7, 'pg_stat_statements must be loaded via "shared_preload_libraries"'];

            throw new QueryException('pgsql', is_string($query) ? $query : '', [], $refused);
        }
    };

    $connection->sqlstate = $sqlstate;

    $manager = app('db');
    $manager->purge('pgsql');
    $manager->extend('pgsql', static fn (): Connection => $connection);
}
