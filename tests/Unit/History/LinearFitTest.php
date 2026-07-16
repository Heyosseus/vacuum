<?php

declare(strict_types=1);

use Heyosseus\Vacuum\History\LinearFit;

it('will not fit a line to fewer than two points', function (): void {
    expect(LinearFit::through([]))->toBeNull()
        ->and(LinearFit::through([[0.0, 1.0]]))->toBeNull();
});

it('will not fit a line to points that all share one x', function (): void {
    // A vertical line has no slope to fit.
    expect(LinearFit::through([[5.0, 1.0], [5.0, 2.0], [5.0, 3.0]]))->toBeNull();
});

it('fits a straight climb exactly', function (): void {
    $fit = LinearFit::through([[0.0, 0.0], [1.0, 2.0], [2.0, 4.0], [3.0, 6.0]]);

    expect($fit)->not->toBeNull()
        ->and($fit->slope)->toBe(2.0)
        ->and($fit->intercept)->toBe(0.0)
        ->and($fit->rSquared)->toBe(1.0);
});

it('scores a scattered series a poor fit', function (): void {
    $fit = LinearFit::through([[0.0, 5.0], [1.0, 1.0], [2.0, 9.0], [3.0, 2.0], [4.0, 8.0]]);

    expect($fit)->not->toBeNull()
        ->and($fit->rSquared)->toBeLessThan(0.5);
});

it('calls a perfectly flat series a perfect fit', function (): void {
    // Nothing varies, so the line through the mean explains all of it.
    $fit = LinearFit::through([[0.0, 3.0], [1.0, 3.0], [2.0, 3.0]]);

    expect($fit?->slope)->toBe(0.0)
        ->and($fit?->rSquared)->toBe(1.0);
});

/**
 * The x this is really fed is a unix timestamp — around 1.78 billion — and the
 * cadence history records at is hourly. The textbook normal-equation form
 * computes n·Σx² − (Σx)², which at that magnitude subtracts two numbers near
 * 4e20 to get a result near 2e10: almost every significant digit cancels, and
 * what survives is noise. Centering x on its own mean first makes the same
 * arithmetic small and well-conditioned, and the slope comes back exact.
 */
it('recovers a known slope from hourly unix timestamps', function (): void {
    $start = 1_784_000_000.0;
    $slope = 0.0015;
    $intercept = 42.0;

    $points = [];

    for ($i = 0; $i < 12; $i++) {
        $x = $start + ($i * 3600.0);
        $points[] = [$x, $intercept + ($slope * $x)];
    }

    $fit = LinearFit::through($points);

    // These points are exactly on a line, so anything short of near-machine
    // precision is the arithmetic losing digits rather than the data being noisy.
    expect(abs($fit->slope - $slope) / $slope)->toBeLessThan(1e-9)
        ->and($fit->rSquared)->toBe(1.0);
});

it('keeps the intercept meaningful after centering', function (): void {
    // Centering shifts x, and the intercept is defined at x = 0, so it has to be
    // shifted back or every forecast built on it lands somewhere else entirely.
    $start = 1_784_000_000.0;
    $slope = 0.0015;
    $intercept = 42.0;

    $points = [];

    for ($i = 0; $i < 12; $i++) {
        $x = $start + ($i * 3600.0);
        $points[] = [$x, $intercept + ($slope * $x)];
    }

    $fit = LinearFit::through($points);

    // The line still passes through its own points, and still solves for the x at
    // which it reaches a given y — which is what a forecast actually asks it.
    $target = $intercept + ($slope * ($start + 86_400.0));

    expect($fit->xFor($target))->toEqualWithDelta($start + 86_400.0, 1.0)
        ->and(($fit->slope * $start) + $fit->intercept)
        ->toEqualWithDelta($intercept + ($slope * $start), 1e-6);
});

it('solves for the x at which the line reaches a y', function (): void {
    $fit = LinearFit::through([[0.0, 0.0], [1.0, 2.0]]);

    expect($fit?->xFor(10.0))->toBe(5.0);
});

it('has no x for a y a flat line never reaches', function (): void {
    $fit = LinearFit::through([[0.0, 3.0], [1.0, 3.0]]);

    expect($fit?->xFor(10.0))->toBeNull();
});
