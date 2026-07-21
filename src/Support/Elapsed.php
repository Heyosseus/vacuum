<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Support;

final class Elapsed
{
    /**
     * A span of time as somebody would have said it out loud.
     *
     * Deliberately coarse and deliberately rounded: this exists so a figure
     * measured across five hours is not labelled "the last interval" as though it
     * were one, and for that the reader needs the order of magnitude, not the
     * seconds. Rounding to a whole unit and pluralising honestly is the whole job.
     */
    public static function human(float $seconds): string
    {
        // Each unit hands over exactly where the next one reaches one of itself,
        // so the commonest answers come out as somebody would say them. The
        // snapshot cadence defaults to hourly, which makes "1 hour" the figure
        // this produces most often and "60 minutes" the least natural way to
        // have said it.
        if ($seconds < 60) {
            return self::quantity((int) round(max($seconds, 0)), 'second');
        }

        if ($seconds < 3_600) {
            return self::quantity((int) round($seconds / 60), 'minute');
        }

        if ($seconds < 86_400) {
            return self::quantity((int) round($seconds / 3_600), 'hour');
        }

        return self::quantity((int) round($seconds / 86_400), 'day');
    }

    private static function quantity(int $count, string $unit): string
    {
        return $count === 1 ? "1 {$unit}" : "{$count} {$unit}s";
    }
}
