<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Inspections\SettingInspection;
use Heyosseus\Vacuum\Advisor\Rules\AutovacuumDisabled;
use Heyosseus\Vacuum\Values\Capabilities;

function configured(string $autovacuum): Capabilities
{
    return new Capabilities(
        serverVersion: 170_005,
        extensions: [],
        settings: ['autovacuum' => $autovacuum],
        readsAllStatistics: true,
    );
}

it('reports what the setting rules find', function (): void {
    // The one inspection that runs no query, so this needs no database: the
    // settings were read once already, when the capabilities were probed.
    $inspection = new SettingInspection(configured('off'), [new AutovacuumDisabled]);

    $findings = $inspection->findings();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('autovacuum-disabled');
});

it('has nothing to say about a server that is configured properly', function (): void {
    $inspection = new SettingInspection(configured('on'), [new AutovacuumDisabled]);

    expect($inspection->findings())->toBe([]);
});
