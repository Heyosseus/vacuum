<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\UnpatchedServer;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Capabilities;

function capable(int $serverVersion, array $extensions = []): Capabilities
{
    return new Capabilities(
        serverVersion: $serverVersion,
        extensions: $extensions,
        settings: [],
        readsAllStatistics: true,
    );
}

it('warns when the server is behind the latest minor for its major', function (): void {
    // PostgreSQL 17's latest minor is 10; this server is on 17.5.
    $finding = (new UnpatchedServer)->inspect(capable(170_005));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('unpatched-server')
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe('server');
});

it('carries all three caveats, because a version string alone cannot prove exposure', function (): void {
    $finding = (new UnpatchedServer)->inspect(capable(170_005));

    expect($finding->impact)->toContain('Debian')
        ->and($finding->impact)->toContain('pgcrypto')
        ->and($finding->impact)->toContain('libpq');
});

it('is content when the server is already on the latest minor', function (): void {
    expect((new UnpatchedServer)->inspect(capable(170_010)))->toBeNull();
});

it('does not report a finding it cannot substantiate for an unrecognised major', function (): void {
    expect((new UnpatchedServer)->inspect(capable(990_000)))->toBeNull();
});
