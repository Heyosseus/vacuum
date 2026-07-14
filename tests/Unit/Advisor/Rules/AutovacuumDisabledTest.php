<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\AutovacuumDisabled;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Capabilities;

function server(string $autovacuum): Capabilities
{
    return new Capabilities(
        serverVersion: 170_005,
        extensions: [],
        settings: ['autovacuum' => $autovacuum, 'track_counts' => 'on'],
        readsAllStatistics: true,
    );
}

it('says nothing about a server running autovacuum', function (): void {
    expect(app(AutovacuumDisabled::class)->inspect(server('on')))->toBeNull();
});

it('calls a server with autovacuum turned off critical', function (): void {
    $finding = app(AutovacuumDisabled::class)->inspect(server('off'));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('autovacuum-disabled')
        ->and($finding->severity)->toBe(Severity::Critical)
        ->and($finding->subject)->toBe('autovacuum');
});

it('does not claim the database will shut down, because it will not', function (): void {
    // PostgreSQL launches an anti-wraparound vacuum even when autovacuum is off.
    // Saying otherwise would be the kind of confident wrongness this package exists
    // to argue against.
    $finding = app(AutovacuumDisabled::class)->inspect(server('off'));

    expect($finding->impact)->toContain('wraparound')
        ->and($finding->impact)->toContain('still');
});

it('offers the setting change and the reload that applies it', function (): void {
    $finding = app(AutovacuumDisabled::class)->inspect(server('off'));

    expect($finding->remediation)->toContain('ALTER SYSTEM SET autovacuum = on;')
        ->and($finding->remediation)->toContain('pg_reload_conf()');
});
