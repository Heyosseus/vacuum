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
     * Whether the value only ever climbs, so a straight line fit through it can be
     * projected forward to when it crosses a threshold. Cumulative counters climb
     * too, but nobody forecasts them; these are the levels a forecast is about.
     */
    public function isMonotonic(): bool
    {
        return match ($this) {
            self::TableXidAge, self::TableBloatBytes => true,
            default => false,
        };
    }
}
