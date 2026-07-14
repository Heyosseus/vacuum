<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Values\BloatEstimate;

it('names the table it is about', function (): void {
    $estimate = new BloatEstimate(
        schema: 'public',
        name: 'crates',
        fillfactor: 100,
        realBytes: 8_192,
        bloatBytes: 0,
    );

    expect($estimate->qualifiedName())->toBe('public.crates');
});

it('reads the share of a table that is wasted', function (): void {
    $estimate = new BloatEstimate(
        schema: 'public',
        name: 'crates',
        fillfactor: 100,
        realBytes: 1_000_000,
        bloatBytes: 250_000,
    );

    expect($estimate->bloatRatio())->toBe(0.25);
});

it('calls a table of no size no bloat rather than dividing by nothing', function (): void {
    $estimate = new BloatEstimate(
        schema: 'public',
        name: 'crates',
        fillfactor: 100,
        realBytes: 0,
        bloatBytes: 0,
    );

    expect($estimate->bloatRatio())->toBe(0.0);
});
