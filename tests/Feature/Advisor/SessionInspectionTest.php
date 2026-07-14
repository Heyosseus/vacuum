<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Inspections\SessionInspection;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    DB::connection('pgsql_bystander')->rollBack();
    DB::purge('pgsql_bystander');
});

function capabilities(bool $readsAllStatistics): Capabilities
{
    return new Capabilities(
        serverVersion: 170_005,
        extensions: [],
        settings: ['track_counts' => 'on'],
        readsAllStatistics: $readsAllStatistics,
    );
}

it('reports a transaction another connection has abandoned', function (): void {
    config()->set('vacuum.thresholds.idle_in_transaction_seconds', 0);

    DB::connection('pgsql_bystander')->beginTransaction();
    DB::connection('pgsql_bystander')->select('SELECT 1');

    expect(array_column(app(SessionInspection::class)->findings(), 'rule'))
        ->toContain('idle-in-transaction');
});

it('says out loud that it cannot see every session', function (): void {
    // Worse than seeing nothing: a role without pg_read_all_stats sees its own
    // sessions and nulls for everyone else's, and a half-empty list of sessions
    // reads exactly like a quiet database.
    app()->instance(Capabilities::class, capabilities(readsAllStatistics: false));

    $findings = app(SessionInspection::class)->findings();

    expect(array_column($findings, 'rule'))->toContain('partial-visibility')
        ->and($findings[0]->remediation)->toBe('GRANT pg_read_all_stats TO current_user;');
});

it('keeps quiet about visibility when it can see everything', function (): void {
    app()->instance(Capabilities::class, capabilities(readsAllStatistics: true));

    expect(array_column(app(SessionInspection::class)->findings(), 'rule'))
        ->not->toContain('partial-visibility');
});
