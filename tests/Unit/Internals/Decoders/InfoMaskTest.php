<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Internals\Decoders\InfoMask;

it('reads a frozen tuple as frozen and not merely as committed', function (): void {
    // HEAP_XMIN_FROZEN is COMMITTED|INVALID -- a pair that means nothing on its
    // own, which is why it was picked to mean frozen. Checked in the wrong
    // order, every frozen tuple reads as an ordinary committed one.
    expect(InfoMask::xminFrozen(0x0300))->toBeTrue()
        ->and(InfoMask::xminFrozen(0x0100))->toBeFalse();
});

it('reads xmin committed only when not also frozen', function (): void {
    expect(InfoMask::xminCommitted(0x0100))->toBeTrue()
        ->and(InfoMask::xminCommitted(0x0000))->toBeFalse();
});

it('reads a live current row version', function (): void {
    expect(InfoMask::xmaxInvalid(0x0800))->toBeTrue()
        ->and(InfoMask::xmaxInvalid(0x0000))->toBeFalse();
});

it('reads xmax committed', function (): void {
    expect(InfoMask::xmaxCommitted(0x0400))->toBeTrue()
        ->and(InfoMask::xmaxCommitted(0x0000))->toBeFalse();
});

it('reads xmax as a multixact', function (): void {
    expect(InfoMask::xmaxIsMulti(0x1000))->toBeTrue()
        ->and(InfoMask::xmaxIsMulti(0x0000))->toBeFalse();
});

it('separates a locked row from a deleted one', function (): void {
    // xmax is set on both. Only HEAP_XMAX_LOCK_ONLY says which.
    expect(InfoMask::xmaxLockOnly(0x0080))->toBeTrue()
        ->and(InfoMask::xmaxLockOnly(0x0400))->toBeFalse();
});

it('reads HOT updated and heap-only tuple from infomask2', function (): void {
    expect(InfoMask::hotUpdated(0x4000))->toBeTrue()
        ->and(InfoMask::hotUpdated(0x0000))->toBeFalse()
        ->and(InfoMask::heapOnlyTuple(0x8000))->toBeTrue()
        ->and(InfoMask::heapOnlyTuple(0x0000))->toBeFalse();
});

it('finds the attribute count in the low eleven bits', function (): void {
    expect(InfoMask::attributeCount(0x8003))->toBe(3);
});

it('exposes the legacy VACUUM FULL move bits without hiding them', function (): void {
    expect(InfoMask::movedByVacuumFull(0x4000))->toBeTrue()
        ->and(InfoMask::movedByVacuumFull(0x8000))->toBeTrue()
        ->and(InfoMask::movedByVacuumFull(0x0000))->toBeFalse();
});

it('describes every readable flag', function (): void {
    expect(InfoMask::describe(0x0001, 0x0000))->toBe(['has nulls'])
        ->and(InfoMask::describe(0x0002, 0x0000))->toBe(['has variable-width attributes'])
        ->and(InfoMask::describe(0x0004, 0x0000))->toBe(['has external attributes'])
        ->and(InfoMask::describe(0x0008, 0x0000))->toBe(['has object id'])
        ->and(InfoMask::describe(0x0010, 0x0000))->toBe(['xmax is a key-shared locker'])
        ->and(InfoMask::describe(0x0020, 0x0000))->toBe(['combo cid'])
        ->and(InfoMask::describe(0x0040, 0x0000))->toBe(['xmax is an exclusive locker'])
        ->and(InfoMask::describe(0x0080, 0x0000))->toBe(['locked only'])
        ->and(InfoMask::describe(0x0100, 0x0000))->toBe(['xmin committed'])
        ->and(InfoMask::describe(0x0300, 0x0000))->toBe(['xmin frozen'])
        ->and(InfoMask::describe(0x0400, 0x0000))->toBe(['xmax committed'])
        ->and(InfoMask::describe(0x0800, 0x0000))->toBe(['xmax invalid'])
        ->and(InfoMask::describe(0x1000, 0x0000))->toBe(['xmax is a multixact'])
        ->and(InfoMask::describe(0x2000, 0x0000))->toBe(['updated row version'])
        ->and(InfoMask::describe(0x0000, 0x2000))->toBe(['key columns updated'])
        ->and(InfoMask::describe(0x0000, 0x4000))->toBe(['HOT updated'])
        ->and(InfoMask::describe(0x0000, 0x8000))->toBe(['heap-only tuple'])
        ->and(InfoMask::describe(0x0000, 0x0000))->toBe([]);
});

it('omits the legacy VACUUM FULL bits from describe()', function (): void {
    expect(InfoMask::describe(0x4000, 0x0000))->toBe([])
        ->and(InfoMask::describe(0x8000, 0x0000))->toBe([]);
});
