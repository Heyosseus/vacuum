<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\EndOfLifeMajor;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Capabilities;

function versioned(int $serverVersion): Capabilities
{
    return new Capabilities(
        serverVersion: $serverVersion,
        extensions: [],
        settings: [],
        readsAllStatistics: true,
    );
}

it('calls it critical once the end-of-life date has already passed', function (): void {
    // 13 went end-of-life 2025-11-13.
    $clock = new DateTimeImmutable('2026-07-19');

    $finding = (new EndOfLifeMajor($clock))->inspect(versioned(130_023));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('end-of-life-major')
        ->and($finding->severity)->toBe(Severity::Critical)
        ->and($finding->subject)->toBe('server');
});

it('warns once the end-of-life date is within 180 days', function (): void {
    // 14 goes end-of-life 2026-11-12; sixty days out counts as "within 180".
    $clock = new DateTimeImmutable('2026-09-13');

    $finding = (new EndOfLifeMajor($clock))->inspect(versioned(140_023));

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Warning);
});

it('is content when the end-of-life date is well in the future', function (): void {
    $clock = new DateTimeImmutable('2026-07-19');

    expect((new EndOfLifeMajor($clock))->inspect(versioned(170_005)))->toBeNull();
});

it('returns null for a major it does not recognise', function (): void {
    expect((new EndOfLifeMajor(new DateTimeImmutable('2026-07-19')))->inspect(versioned(990_000)))->toBeNull();
});

it('resolves its own clock when none is given', function (): void {
    // Not asserting a specific outcome, since real time moves -- only that a
    // rule built with no arguments does not blow up reaching for now().
    expect((new EndOfLifeMajor)->inspect(versioned(170_005)))->toBeNull();
});
