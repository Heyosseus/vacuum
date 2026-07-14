<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Support\Bytes;

it('leaves a size a person can already read alone', function (): void {
    expect(Bytes::human(0))->toBe('0 B')
        ->and(Bytes::human(512))->toBe('512 B');
});

it('scales a size up to the unit a person would have used', function (): void {
    expect(Bytes::human(1_536))->toBe('1.5 KB')
        ->and(Bytes::human(100 * 1024 * 1024))->toBe('100.0 MB')
        ->and(Bytes::human(3 * 1024 * 1024 * 1024))->toBe('3.0 GB');
});
