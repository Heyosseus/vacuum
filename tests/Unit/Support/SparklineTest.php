<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Support\Sparkline;

it('draws nothing from nothing', function (): void {
    expect(Sparkline::points([]))->toBe('');
});

it('draws a single value as a flat mark across the middle', function (): void {
    // One point is not a line. Rather than an empty box, it sits on the midline so
    // the panel shows something.
    expect(Sparkline::points([42], 100.0, 30.0, 2.0))->toBe('2,15 98,15');
});

it('sits a flat run on the midline rather than dividing by zero', function (): void {
    $points = Sparkline::points([50, 50, 50], 100.0, 30.0, 2.0);

    // Every y is the midline; the xs march across from padding to width minus padding.
    expect($points)->toBe('2,15 50,15 98,15');
});

it('puts higher values higher up the inverted svg axis', function (): void {
    // Lowest value sits at the bottom (largest y), highest at the top (smallest y).
    $points = Sparkline::points([0, 10], 100.0, 30.0, 2.0);

    expect($points)->toBe('2,28 98,2');
});

it('spaces the points evenly across the width', function (): void {
    $points = explode(' ', Sparkline::points([1, 2, 3, 4, 5], 100.0, 30.0, 2.0));

    expect($points)->toHaveCount(5)
        ->and($points[0])->toStartWith('2,')
        ->and($points[4])->toStartWith('98,');
});
