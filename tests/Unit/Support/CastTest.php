<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Support\Cast;

it('reads text out of whatever scalar the driver reported', function (): void {
    expect(Cast::text('public'))->toBe('public')
        ->and(Cast::text(42))->toBe('42')
        ->and(Cast::text(null))->toBe('')
        ->and(Cast::text(['unexpected']))->toBe('');
});

it('reads a count out of the string a driver reports a bigint as', function (): void {
    expect(Cast::integer('1200'))->toBe(1200)
        ->and(Cast::integer(7))->toBe(7)
        ->and(Cast::integer(null))->toBe(0)
        ->and(Cast::integer('not a number'))->toBe(0);
});

it('reads a boolean whichever way postgres spelled it', function (): void {
    expect(Cast::boolean(true))->toBeTrue()
        ->and(Cast::boolean('t'))->toBeTrue()
        ->and(Cast::boolean('on'))->toBeTrue()
        ->and(Cast::boolean(false))->toBeFalse()
        ->and(Cast::boolean('f'))->toBeFalse()
        ->and(Cast::boolean(null))->toBeFalse();
});

it('reads a timestamp, and reads nothing at all as never', function (): void {
    expect(Cast::timestamp('2026-07-13 09:30:00'))
        ->toEqual(CarbonImmutable::parse('2026-07-13 09:30:00'))
        ->and(Cast::timestamp(null))->toBeNull()
        ->and(Cast::timestamp(''))->toBeNull();
});
