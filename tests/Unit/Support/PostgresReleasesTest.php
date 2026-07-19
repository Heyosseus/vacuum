<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Support\PostgresReleases;

it('knows the newest patch release of a supported major', function (): void {
    expect(PostgresReleases::latestMinor(17))->toBe(10)
        ->and(PostgresReleases::endOfLife(14))->toBe('2026-11-12');
});

it('admits it does not recognise a major rather than guessing', function (): void {
    // A version table is a fact about the world, and the world moves. Guessing
    // would mean telling somebody their supported server is unsupported.
    expect(PostgresReleases::latestMinor(99))->toBeNull()
        ->and(PostgresReleases::endOfLife(99))->toBeNull();
});
