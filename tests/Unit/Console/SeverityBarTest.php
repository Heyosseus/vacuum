<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Console\Support\SeverityBar;

// Not finding(): that name is already a global Pest helper elsewhere in the suite.
function barFinding(Severity $severity): Finding
{
    return new Finding(
        rule: 'stub', subject: 'public.orders', severity: $severity,
        summary: 'stub', impact: 'stub',
    );
}

it('accepts the four names it documents', function (): void {
    foreach (['critical', 'warning', 'info', 'never'] as $name) {
        expect(SeverityBar::parse($name))->toBeInstanceOf(SeverityBar::class);
    }
});

it('refuses a name it does not know', function (): void {
    expect(SeverityBar::parse('catastrophic'))->toBeNull()
        ->and(SeverityBar::parse(null))->toBeNull();
});

it('fails on findings at or above the bar', function (): void {
    expect(SeverityBar::parse('warning')?->fails([barFinding(Severity::Critical)]))->toBeTrue()
        ->and(SeverityBar::parse('warning')?->fails([barFinding(Severity::Warning)]))->toBeTrue()
        ->and(SeverityBar::parse('warning')?->fails([barFinding(Severity::Info)]))->toBeFalse();
});

it('never fails at the never bar', function (): void {
    expect(SeverityBar::parse('never')?->fails([barFinding(Severity::Critical)]))->toBeFalse();
});

it('never fails a build on an unknown', function (): void {
    // A build should go red because the database has a problem, never because
    // the role was short a grant. Said out loud so that reordering the ranks
    // cannot quietly change it.
    expect(SeverityBar::parse('info')?->fails([barFinding(Severity::Unknown)]))->toBeFalse();
});
