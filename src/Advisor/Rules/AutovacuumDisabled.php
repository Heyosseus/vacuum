<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\SettingRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Capabilities;

/**
 * Finds a server with autovacuum switched off.
 *
 * Almost nobody turns this off on purpose and leaves it off on purpose. It gets
 * turned off to get through a bulk load or a migration, and then the load finishes
 * and the setting stays, and for a while nothing appears to be wrong, because
 * nothing is: the tables simply stop being cleaned up, and the cost arrives weeks
 * later as bloat nobody can explain and plans nobody can account for.
 */
final readonly class AutovacuumDisabled implements SettingRule
{
    public function inspect(Capabilities $capabilities): ?Finding
    {
        if ($capabilities->enabled('autovacuum')) {
            return null;
        }

        return new Finding(
            rule: 'autovacuum-disabled',
            subject: 'autovacuum',
            severity: Severity::Critical,
            summary: 'Autovacuum is switched off on this server. Nothing is reclaiming dead rows or refreshing '
                .'the statistics the planner reads.',
            impact: 'Every deleted and every updated row stays on disk, so tables grow and never shrink, and '
                .'sequential scans read pages full of rows no query can see. The planner keeps working from the '
                .'statistics of whenever the table was last analyzed by hand. PostgreSQL will still launch a '
                .'vacuum on its own to prevent transaction wraparound, so the database is not going to shut down '
                .'over this — but that emergency vacuum arrives without warning, on a table by then far larger '
                .'than it should be, and it is not the gentle background work autovacuum was doing for you.',
            remediation: "ALTER SYSTEM SET autovacuum = on;\nSELECT pg_reload_conf();",
        );
    }
}
