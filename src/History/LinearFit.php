<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\History;

/**
 * The straight line least-squares fits through a set of points, and how well it
 * fits them.
 *
 * A forecast is only as honest as the fit under it: a line drawn through points
 * that do not sit on a line is a guess wearing the clothes of a measurement. The
 * r-squared is kept precisely so the caller can refuse to forecast when the points
 * do not agree with the line, rather than project a number nobody should trust.
 */
final readonly class LinearFit
{
    public function __construct(
        public float $slope,
        public float $intercept,
        public float $rSquared,
    ) {}

    /**
     * @param  list<array{0: float, 1: float}>  $points  [x, y] pairs, at least two,
     *                                                   with at least two distinct x.
     */
    public static function through(array $points): ?self
    {
        $n = count($points);

        if ($n < 2) {
            return null;
        }

        $sumX = $sumY = 0.0;

        foreach ($points as [$x, $y]) {
            $sumX += $x;
            $sumY += $y;
        }

        $meanX = $sumX / $n;
        $meanY = $sumY / $n;

        // x arrives as a unix timestamp — around 1.78 billion — and the textbook
        // form of this computes n·Σx² − (Σx)², which at that magnitude subtracts
        // two numbers near 4e20 to produce one near 2e10. Fifteen significant
        // digits go in and about nine come out; the rest is cancellation.
        //
        // Centering each x on the mean first makes both sums small and the
        // subtraction unnecessary: the deviations are of the order of the spacing
        // between snapshots rather than of the epoch. The slope is unchanged by a
        // shift along x, so this costs nothing and the intercept is shifted back
        // below.
        $covariance = $variance = 0.0;

        foreach ($points as [$x, $y]) {
            $dx = $x - $meanX;
            $covariance += $dx * ($y - $meanY);
            $variance += $dx * $dx;
        }

        // Every point shares one x: a vertical line, which has no slope to fit.
        // The guard still holds after centering — identical x values all centre to
        // exactly zero, so the variance is exactly zero too.
        if ($variance === 0.0) {
            return null;
        }

        $slope = $covariance / $variance;

        // The fit was found around the mean; the intercept is defined at x = 0, so
        // it is carried back to where the caller expects it.
        $intercept = $meanY - ($slope * $meanX);

        return new self($slope, $intercept, self::rSquared($points, $slope, $intercept, $meanY));
    }

    /**
     * The x at which the line reaches a given y, or null when the line is flat and
     * never reaches it.
     */
    public function xFor(float $y): ?float
    {
        if ($this->slope === 0.0) {
            return null;
        }

        return ($y - $this->intercept) / $this->slope;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     */
    private static function rSquared(array $points, float $slope, float $intercept, float $meanY): float
    {
        $residual = $total = 0.0;

        foreach ($points as [$x, $y]) {
            $predicted = ($slope * $x) + $intercept;
            $residual += ($y - $predicted) ** 2;
            $total += ($y - $meanY) ** 2;
        }

        // A perfectly flat series explains itself: every point is the mean.
        if ($total === 0.0) {
            return 1.0;
        }

        return max(0.0, 1.0 - ($residual / $total));
    }
}
