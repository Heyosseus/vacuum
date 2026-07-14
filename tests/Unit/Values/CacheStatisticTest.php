<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Values\CacheStatistic;

it('reads the share of blocks that never went to disk', function (): void {
    $cache = new CacheStatistic(blocksHit: 990, blocksRead: 10, countingSince: null);

    expect($cache->hitRatio())->toBe(0.99)
        ->and($cache->blocksRequested())->toBe(1_000);
});

it('calls a database nobody has read from a perfect one', function (): void {
    // Nothing was asked for, so nothing was missed. The rule refuses to judge a
    // sample this small anyway; this only keeps the arithmetic honest.
    $cache = new CacheStatistic(blocksHit: 0, blocksRead: 0, countingSince: null);

    expect($cache->hitRatio())->toBe(1.0);
});
