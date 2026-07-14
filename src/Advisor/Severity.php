<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

enum Severity: string
{
    /** Worth knowing, costing nothing today. */
    case Info = 'info';

    /** Costing something measurable; fix it when convenient. */
    case Warning = 'warning';

    /** Costing something now, or heading somewhere that will hurt. */
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }

    /**
     * What a finding of this severity costs the health score.
     *
     * An Info finding costs nothing: it is a fact, not a fault, and a server
     * should not be marked down for telling you the truth about what it cannot see.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 15,
            self::Warning => 5,
            self::Info => 0,
        };
    }
}
