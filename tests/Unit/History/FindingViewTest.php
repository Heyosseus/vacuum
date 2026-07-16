<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\History\FindingView;
use Heyosseus\Vacuum\History\Trend;

function cacheHitFinding(): Finding
{
    return new Finding(
        rule: 'cache-hit-ratio',
        subject: 'database',
        severity: Severity::Warning,
        summary: 'the lifetime sentence',
        impact: 'impact',
    );
}

it('shows the interval sentence when history could compute one', function (): void {
    $view = new FindingView(cacheHitFinding(), Trend::Flat, null, 'the interval sentence');

    expect($view->summary())->toBe('the interval sentence');
});

it('falls back to the finding sentence when there is no interval one', function (): void {
    $view = new FindingView(cacheHitFinding(), Trend::Flat, null, null);

    expect($view->summary())->toBe('the lifetime sentence');
});

it('says when its number is rising or easing', function (): void {
    $rising = new FindingView(cacheHitFinding(), Trend::Rising, null, null);
    $falling = new FindingView(cacheHitFinding(), Trend::Falling, null, null);
    $flat = new FindingView(cacheHitFinding(), Trend::Flat, null, null);

    expect($rising->isRising())->toBeTrue()
        ->and($rising->isFalling())->toBeFalse()
        ->and($falling->isFalling())->toBeTrue()
        ->and($falling->isRising())->toBeFalse()
        ->and($flat->isRising())->toBeFalse()
        ->and($flat->isFalling())->toBeFalse();
});
