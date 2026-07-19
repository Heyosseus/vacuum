<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Support;

use Heyosseus\Vacuum\Advisor\Severity;

/**
 * The Filament colour a finding of a given severity is painted in.
 *
 * Filament names its colours rather than spelling them, so this is the one place
 * the advisor's four severities are married to the panel's palette: danger for
 * something costing you now, warning for something that will, and a neutral gray
 * for both the merely informational and the unknown -- because an Info finding is
 * a fact and not a fault, an Unknown finding is neither fine nor wrong, and
 * colouring either like an alarm would cry wolf.
 */
final class SeverityColor
{
    public static function for(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical => 'danger',
            Severity::Warning => 'warning',
            Severity::Info, Severity::Unknown => 'gray',
        };
    }
}
