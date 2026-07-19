<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\AutovacuumWorkersVersusCost;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Settings;

it('warns when more workers were added without a cost limit to share between them', function (): void {
    $finding = (new AutovacuumWorkersVersusCost)->inspect(new Settings([
        'autovacuum_max_workers' => setting('autovacuum_max_workers', '6'),
        'autovacuum_vacuum_cost_limit' => setting('autovacuum_vacuum_cost_limit', '-1'),
    ]));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('autovacuum-workers-vs-cost')
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe('server');
});

it('is content with the default worker count', function (): void {
    expect((new AutovacuumWorkersVersusCost)->inspect(new Settings([
        'autovacuum_max_workers' => setting('autovacuum_max_workers', '3'),
        'autovacuum_vacuum_cost_limit' => setting('autovacuum_vacuum_cost_limit', '-1'),
    ])))->toBeNull();
});

it('is content once the cost limit was actually raised to match the workers', function (): void {
    expect((new AutovacuumWorkersVersusCost)->inspect(new Settings([
        'autovacuum_max_workers' => setting('autovacuum_max_workers', '6'),
        'autovacuum_vacuum_cost_limit' => setting('autovacuum_vacuum_cost_limit', '1200'),
    ])))->toBeNull();
});
