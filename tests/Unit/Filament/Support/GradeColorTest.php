<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Grade;
use Heyosseus\Vacuum\Filament\Support\GradeColor;

it('paints a database in good order green', function (): void {
    expect(GradeColor::for(Grade::A))->toBe('success')
        ->and(GradeColor::for(Grade::B))->toBe('success');
});

it('paints a middling grade amber', function (): void {
    expect(GradeColor::for(Grade::C))->toBe('warning');
});

it('paints a graded-down database red, so a capped D reads as the alarm it is', function (): void {
    expect(GradeColor::for(Grade::D))->toBe('danger')
        ->and(GradeColor::for(Grade::F))->toBe('danger');
});
