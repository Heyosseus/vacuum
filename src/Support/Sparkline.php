<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Support;

/**
 * Turns a run of numbers into the points of an SVG polyline, so a trend can be
 * drawn inline without a charting library or a byte of JavaScript.
 *
 * The package ships inside someone else's application and may not load a script of
 * its own; an SVG the server writes out in full is the one chart that always
 * renders. The output is a coordinate string for a <polyline points="...">, scaled
 * to a viewBox the caller decides.
 */
final class Sparkline
{
    /**
     * @param  list<float|int>  $values  Oldest first.
     */
    public static function points(array $values, float $width = 100.0, float $height = 30.0, float $padding = 2.0): string
    {
        $count = count($values);

        if ($count === 0) {
            return '';
        }

        // One point has no line; draw it as a flat mark across the middle so the
        // panel shows something rather than an empty box.
        if ($count === 1) {
            $mid = $height / 2;

            return sprintf('%s,%s %s,%s', $padding, $mid, $width - $padding, $mid);
        }

        // Cast to float before subtracting: min/max of an all-integer series return
        // ints, and an integer zero span would slip past the `=== 0.0` guard below
        // straight into a division by it.
        $min = (float) min($values);
        $max = (float) max($values);
        $span = $max - $min;

        $usableWidth = $width - (2 * $padding);
        $usableHeight = $height - (2 * $padding);

        $points = [];

        foreach ($values as $index => $value) {
            $x = $padding + ($usableWidth * ($index / ($count - 1)));

            // A flat series has no span to scale against; sit it on the midline
            // rather than divide by zero. Otherwise higher values sit higher, so y
            // is inverted against SVG's downward axis.
            $y = $span === 0.0
                ? $height / 2
                : $padding + ($usableHeight * (1 - (($value - $min) / $span)));

            $points[] = round($x, 2).','.round($y, 2);
        }

        return implode(' ', $points);
    }
}
