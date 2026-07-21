<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Support\Elapsed;

it('names a span the way somebody would say it', function (float $seconds, string $said): void {
    expect(Elapsed::human($seconds))->toBe($said);
})->with([
    'a moment' => [0.0, '0 seconds'],
    'one second' => [1.0, '1 second'],
    'under a minute and a half stays in seconds' => [45.0, '45 seconds'],
    'a minute' => [60.0, '1 minute'],
    'several minutes' => [900.0, '15 minutes'],
    // The snapshot cadence defaults to hourly, so this is the most common answer
    // this ever gives; "60 minutes" would have been the least natural way to say
    // the ordinary case.
    'an hour' => [3_600.0, '1 hour'],
    'the five-hour gap a reshuffled statement list produces' => [18_000.0, '5 hours'],
    'a day' => [86_400.0, '1 day'],
    'several days' => [604_800.0, '7 days'],
]);

it('never reports a negative span', function (): void {
    // Two snapshots cannot be out of order, but a clock that moved backwards
    // between them is not this function's problem to describe.
    expect(Elapsed::human(-10.0))->toBe('0 seconds');
});
