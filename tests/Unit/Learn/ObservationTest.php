<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Observation;

it('is empty when it has no rows', function (): void {
    $observation = new Observation(headline: 'No table has ever been updated.');

    expect($observation->isEmpty())->toBeTrue();
});

it('is not empty once a row has something to show', function (): void {
    $observation = new Observation(
        headline: 'public.orders holds 4,201 dead rows.',
        columns: ['table', 'dead rows'],
        rows: [['public.orders', '4,201']],
    );

    expect($observation->isEmpty())->toBeFalse();
});
