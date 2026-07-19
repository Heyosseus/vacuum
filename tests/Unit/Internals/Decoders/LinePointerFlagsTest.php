<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Internals\Decoders\LinePointerFlags;

it('names every line pointer state', function (): void {
    expect(LinePointerFlags::describe(0))->toBe('unused')
        ->and(LinePointerFlags::describe(1))->toBe('normal')
        ->and(LinePointerFlags::describe(2))->toBe('redirect')
        ->and(LinePointerFlags::describe(3))->toBe('dead');
});

it('reports unknown for an unrecognised value rather than throwing', function (): void {
    expect(LinePointerFlags::describe(99))->toBe('unknown');
});
