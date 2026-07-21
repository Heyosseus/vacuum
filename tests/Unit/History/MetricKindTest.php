<?php

declare(strict_types=1);

use Heyosseus\Vacuum\History\MetricKind;

it('maps a rule to the metric that carries its headline number', function (): void {
    expect(MetricKind::forRule('wraparound'))->toBe(MetricKind::TableXidAge)
        ->and(MetricKind::forRule('table-bloat'))->toBe(MetricKind::TableBloatBytes)
        ->and(MetricKind::forRule('dead-tuples'))->toBe(MetricKind::TableDeadTuples);
});

it('has no metric for a rule that is not a number that trends', function (): void {
    expect(MetricKind::forRule('duplicate-index'))->toBeNull()
        ->and(MetricKind::forRule('cache-hit-ratio'))->toBeNull();
});

it('knows which kinds are cumulative counters', function (): void {
    expect(MetricKind::DbCache->isCumulative())->toBeTrue()
        ->and(MetricKind::Statement->isCumulative())->toBeTrue()
        ->and(MetricKind::TableXidAge->isCumulative())->toBeFalse()
        ->and(MetricKind::TableBloatBytes->isCumulative())->toBeFalse()
        ->and(MetricKind::TableDeadTuples->isCumulative())->toBeFalse();
});

it('knows which kinds a forecast may be drawn through', function (): void {
    expect(MetricKind::TableXidAge->isForecastable())->toBeTrue()
        ->and(MetricKind::TableBloatBytes->isForecastable())->toBeTrue()
        ->and(MetricKind::TableDeadTuples->isForecastable())->toBeFalse()
        ->and(MetricKind::DbCache->isForecastable())->toBeFalse()
        ->and(MetricKind::Statement->isForecastable())->toBeFalse();
});
