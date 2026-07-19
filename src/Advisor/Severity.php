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

    /**
     * Something Vacuum could not see, so it is neither fine nor wrong.
     *
     * PostgreSQL does not refuse a query a role lacks the privilege to answer
     * fully -- it answers it with nulls. A role without pg_read_all_stats asking
     * pg_stat_activity for active sessions gets back an empty set, which is
     * shaped exactly like a healthy server. This severity is how a panel says
     * "I looked and could not see" instead of quietly saying "nothing is wrong".
     */
    case Unknown = 'unknown';

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Warning => 1,
            self::Info => 2,
            self::Unknown => 3,
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
            self::Info, self::Unknown => 0,
        };
    }
}
