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

it('solves for the x at which the line reaches a y', function (): void {
    $fit = LinearFit::through([[0.0, 0.0], [1.0, 2.0]]);

    expect($fit?->xFor(10.0))->toBe(5.0);
});

it('has no x for a y a flat line never reaches', function (): void {
    $fit = LinearFit::through([[0.0, 3.0], [1.0, 3.0]]);

    expect($fit?->xFor(10.0))->toBeNull();
});
