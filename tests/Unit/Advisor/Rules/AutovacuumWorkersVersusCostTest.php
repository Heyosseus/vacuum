<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\AutovacuumWorkersVersusCost;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Settings;

/**
 * The three settings this rule reasons over. vacuum_cost_limit is not optional
 * scenery: -1 in autovacuum_vacuum_cost_limit means "inherit that one", so a
 * fixture that omits it is a server whose real budget is unknowable.
 */
function costSettings(string $workers, string $autovacuumLimit, string $vacuumLimit = '200'): Settings
{
    return new Settings([
        'autovacuum_max_workers' => setting('autovacuum_max_workers', $workers),
        'autovacuum_vacuum_cost_limit' => setting('autovacuum_vacuum_cost_limit', $autovacuumLimit),
        'vacuum_cost_limit' => setting('vacuum_cost_limit', $vacuumLimit),
    ]);
}

it('warns when more workers were added without a cost limit to share between them', function (): void {
    $finding = (new AutovacuumWorkersVersusCost)->inspect(costSettings('6', '-1'));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('autovacuum-workers-vs-cost')
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe('server');
});

it('is content with the default worker count', function (): void {
    expect((new AutovacuumWorkersVersusCost)->inspect(costSettings('3', '-1')))->toBeNull();
});

it('is content once the cost limit was actually raised to match the workers', function (): void {
    expect((new AutovacuumWorkersVersusCost)->inspect(costSettings('6', '1200')))->toBeNull();
});

it('is content when -1 inherits a vacuum_cost_limit that was already raised', function (): void {
    // The false positive the sentinel check used to produce. Raising
    // vacuum_cost_limit is the correct way to raise the budget -- it is the one
    // place that feeds both manual and automatic vacuum -- and leaving
    // autovacuum_vacuum_cost_limit at -1 is how you inherit it. This server did
    // everything right and used to be told it "was never raised".
    expect((new AutovacuumWorkersVersusCost)->inspect(costSettings('6', '-1', '2000')))->toBeNull();
});

it('warns when many workers split a cost limit somebody set explicitly to the default', function (): void {
    // The false negative. Ten workers sharing 200 is exactly the pathology this
    // rule describes, and because the value is not the sentinel the old check
    // said nothing at all.
    $finding = (new AutovacuumWorkersVersusCost)->inspect(costSettings('10', '200'));

    expect($finding)->not->toBeNull()
        ->and($finding->summary)->toContain('10')
        ->and($finding->summary)->toContain('200');
});

it('says nothing when it cannot learn what -1 would inherit', function (): void {
    // A role the server hides vacuum_cost_limit from leaves the effective budget
    // unknown, and a rule that cannot compute its own comparison must not guess
    // at one.
    expect((new AutovacuumWorkersVersusCost)->inspect(new Settings([
        'autovacuum_max_workers' => setting('autovacuum_max_workers', '6'),
        'autovacuum_vacuum_cost_limit' => setting('autovacuum_vacuum_cost_limit', '-1'),
    ])))->toBeNull();
});

it('says nothing when it cannot read the autovacuum cost limit at all', function (): void {
    // A role without pg_read_all_settings does not see every row of pg_settings,
    // and a rule whose whole comparison is one of the missing ones has no opinion
    // to offer rather than a default to assume.
    expect((new AutovacuumWorkersVersusCost)->inspect(new Settings([
        'autovacuum_max_workers' => setting('autovacuum_max_workers', '6'),
        'vacuum_cost_limit' => setting('vacuum_cost_limit', '200'),
    ])))->toBeNull();
});
