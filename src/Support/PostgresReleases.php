<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Support;

/**
 * What PostgreSQL's own release schedule says about each supported major version.
 *
 * A fact about the world, current as of 2026-07-19, and stale the moment the
 * world moves on. PostgreSQL ships a round of minor releases on the second
 * Thursday of February, May, August and November, so this table is good for at
 * most a quarter before it needs checking against postgresql.org/support/versioning
 * again.
 *
 * "Unrecognised" splits in two, and the direction matters. A major *newer* than
 * this table is one PostgreSQL released after this file was written, and both
 * methods answer null rather than guess -- a table that invents an answer there
 * would eventually tell somebody their brand-new server is unsupported. A major
 * *older* than the floor needs no table at all: every one of them is long dead,
 * and answering null for those is how a PostgreSQL 12 cluster came to pass the
 * rule whose entire job is catching it.
 */
final class PostgresReleases
{
    /**
     * The latest minor release shipped for each supported major, keyed by major.
     *
     * @var array<int, int>
     */
    private const array LATEST_MINOR = [
        18 => 4,
        17 => 10,
        16 => 14,
        15 => 18,
        14 => 23,
        13 => 23,
    ];

    /**
     * The date, in Y-m-d, each major stops receiving fixes at all -- including
     * security fixes. Several of these have already passed.
     *
     * @var array<int, string>
     */
    private const array END_OF_LIFE = [
        18 => '2030-11-14',
        17 => '2029-11-08',
        16 => '2028-11-09',
        15 => '2027-11-11',
        14 => '2026-11-12',
        13 => '2025-11-13',
        12 => '2024-11-14',
        11 => '2023-11-09',
        10 => '2022-11-10',
    ];

    public static function latestMinor(int $major): ?int
    {
        return self::LATEST_MINOR[$major] ?? null;
    }

    public static function endOfLife(int $major): ?string
    {
        return self::END_OF_LIFE[$major] ?? null;
    }

    /**
     * Whether this major is older than anything the table bothers to date.
     *
     * PostgreSQL 9.6 went out of support in 2021 and everything before it earlier
     * still. There is no value in carrying dates back through them: below the
     * floor, "unknown" and "dead for years" are the same answer, and the useful
     * thing to say is the second one.
     */
    public static function isBelowSupportFloor(int $major): bool
    {
        return $major < min(array_keys(self::END_OF_LIFE));
    }
}
