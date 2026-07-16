<?php

declare(strict_types=1);

use Heyosseus\Vacuum\History\Trend;

it('names each direction a number can move', function (): void {
    expect(Trend::Rising->value)->toBe('rising')
        ->and(Trend::Falling->value)->toBe('falling')
        ->and(Trend::Flat->value)->toBe('flat')
        ->and(Trend::Unknown->value)->toBe('unknown');
});
