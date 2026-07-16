<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\History\Forecast;

it('holds when a number is projected to cross the line, and how fast', function (): void {
    $at = CarbonImmutable::parse('2026-08-01 00:00:00');
    $forecast = new Forecast(reachesAt: $at, days: 9, perDay: 5_000_000.0);

    expect($forecast->reachesAt)->toBe($at)
        ->and($forecast->days)->toBe(9)
        ->and($forecast->perDay)->toBe(5_000_000.0);
});
