<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Filament\Support\SeverityColor;

it('paints a critical finding in Filament danger red', function (): void {
    expect(SeverityColor::for(Severity::Critical))->toBe('danger');
});

it('paints a warning finding in Filament warning amber', function (): void {
    expect(SeverityColor::for(Severity::Warning))->toBe('warning');
});

it('paints an info finding a neutral gray, because it is a fact and not a fault', function (): void {
    expect(SeverityColor::for(Severity::Info))->toBe('gray');
});

it('paints an unknown neutrally rather than as an alarm', function (): void {
    expect(SeverityColor::for(Severity::Unknown))->toBe('gray');
});
