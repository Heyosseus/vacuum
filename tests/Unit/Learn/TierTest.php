<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Curriculum;
use Heyosseus\Vacuum\Learn\Tier;

it('teaches Eloquent before the storage it sits on', function (): void {
    expect(Tier::Eloquent->order())->toBe(0)
        ->and(Tier::Eloquent->label())->toBe('Eloquent & Laravel')
        ->and(Tier::Foundations->order())->toBe(1)
        ->and(Tier::Advanced->order())->toBe(5);
});

it('orders every tier uniquely', function (): void {
    $orders = array_map(static fn (Tier $tier): int => $tier->order(), Tier::cases());

    expect($orders)->toHaveCount(count(array_unique($orders)));
});

it('has fillfactor build on row versions', function (): void {
    expect(app(Curriculum::class)->find('fillfactor')?->after())->toBe('row-versions');
});

it('lets a lesson decline to state a fork', function (): void {
    // row-versions has no fork of its own; every other lesson does by now.
    expect(app(Curriculum::class)->find('row-versions')?->tree())->toBeNull();
});
