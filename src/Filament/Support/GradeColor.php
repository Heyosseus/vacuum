<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Support;

use Heyosseus\Vacuum\Advisor\Grade;

/**
 * The Filament colour a health grade is painted in.
 *
 * The same idea as SeverityColor, one letter up: A and B are a database in good order
 * and read green, C is amber, and D and F are red because a critical finding caps the
 * grade at D and a reader should see that ceiling as an alarm, not a passing mark.
 */
final class GradeColor
{
    public static function for(Grade $grade): string
    {
        return match ($grade) {
            Grade::A, Grade::B => 'success',
            Grade::C => 'warning',
            Grade::D, Grade::F => 'danger',
        };
    }
}
