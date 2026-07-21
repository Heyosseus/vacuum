<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History;

/**
 * The series a metric row belongs to.
 *
 * A kind names what is being measured; the metric row's `object` names of what.
 * Two of these are cumulative counters that only mean something as a difference
 * between snapshots (db_cache, statement); the rest are levels that mean something
 * on their own and whose direction over time is the point.
 */
enum MetricKind: string
{
    case TableXidAge = 'table_xid_age';
    case TableBloatBytes = 'table_bloat_bytes';
    case TableDeadTuples = 'table_dead_tuples';
    case DbCache = 'db_cache';
    case Statement = 'statement';

    /**
     * The metric that carries a finding's headline number, so a finding can be
     * shown next to its own series and annotated with which way it is moving. Not
     * every rule has one — a duplicate index is not a number that trends — and
     * those return null.
     */
    public static function forRule(string $rule): ?self
    {
        return match ($rule) {
            'wraparound' => self::TableXidAge,
            'table-bloat' => self::TableBloatBytes,
            'dead-tuples' => self::TableDeadTuples,
            default => null,
        };
    }

    /**
     * Whether the stored value is a running total since the server (or the stats)
     * last reset, rather than a level. Cumulative kinds are read as the delta
     * between two snapshots; level kinds are read as they stand.
     */
    public function isCumulative(): bool
    {
        return match ($this) {
            self::DbCache, self::Statement => true,
            default => false,
        };
    }

    /**
     * Whether a threshold crossing is a meaningful thing to project for this kind.
     *
     * Not the same claim as "only ever climbs", which these do not: age(relfrozenxid)
     * drops to nearly nothing on every freeze and bloat drops on every VACUUM FULL,
     * so both are sawtooths. What makes them forecastable is that each *tooth* is a
     * climb toward a fixed limit that matters -- which is why the fit is taken over
     * the climb since the last reset rather than over the whole series. Cumulative
     * counters climb far more reliably and are not here: nobody needs to know when
     * a block-read total will reach a number.
     */
    public function isForecastable(): bool
    {
        return match ($this) {
            self::TableXidAge, self::TableBloatBytes => true,
            default => false,
        };
    }
}
