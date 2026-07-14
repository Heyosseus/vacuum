<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Inspections\StatementInspection;
use Heyosseus\Vacuum\Values\Capabilities;
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
        settings: ['track_counts' => 'on'],
        readsAllStatistics: true,
    ));

    $findings = app(StatementInspection::class)->findings();

    expect(array_column($findings, 'rule'))->toBe(['extension-missing'])
        ->and($findings[0]->remediation)->toBe('CREATE EXTENSION pg_stat_statements;')
        ->and($findings[0]->impact)->toContain('shared_preload_libraries');
});

function extensionInstalled(): bool
{
    return DB::table('pg_extension')->where('extname', 'pg_stat_statements')->exists();
}
