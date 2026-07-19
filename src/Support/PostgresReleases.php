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
 * again. An unrecognised major answers null from both methods rather than a
 * guess: a version table that invents an answer for a major it has never heard
 * of would eventually tell somebody their supported server is unsupported.
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
    ];

    public static function latestMinor(int $major): ?int
    {
        return self::LATEST_MINOR[$major] ?? null;
    }

    public static function endOfLife(int $major): ?string
    {
        return self::END_OF_LIFE[$major] ?? null;
    }
}
