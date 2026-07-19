<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Internals\Decoders\Ctid;

it('reads a tuple pointer', function (): void {
    expect(Ctid::parse('(7,3)'))->toBe(['block' => 7, 'offset' => 3]);
});

it('reads a malformed pointer as block 0, offset 0 rather than throwing', function (): void {
    expect(Ctid::parse('not-a-ctid'))->toBe(['block' => 0, 'offset' => 0]);
});

it('knows a row version that supersedes nothing points at itself', function (): void {
    expect(Ctid::pointsToSelf('(7,3)', 7, 3))->toBeTrue()
        ->and(Ctid::pointsToSelf('(7,4)', 7, 3))->toBeFalse();
});
