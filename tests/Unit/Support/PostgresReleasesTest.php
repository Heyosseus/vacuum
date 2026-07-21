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

it('dates the majors that have already closed', function (): void {
    expect(PostgresReleases::endOfLife(12))->toBe('2024-11-14')
        ->and(PostgresReleases::endOfLife(11))->toBe('2023-11-09')
        ->and(PostgresReleases::endOfLife(10))->toBe('2022-11-10');
});

it('knows a major below its floor is long dead rather than merely unrecognised', function (): void {
    // Two kinds of "not in the table", and they need opposite answers: below the
    // floor every major has been out of support for years, above the top the
    // version is simply newer than this file.
    expect(PostgresReleases::isBelowSupportFloor(9))->toBeTrue()
        ->and(PostgresReleases::isBelowSupportFloor(10))->toBeFalse()
        ->and(PostgresReleases::isBelowSupportFloor(99))->toBeFalse();
});
