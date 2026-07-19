<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\ConfigurationRule;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Settings;

/**
 * Finds a setting somebody already decided on, that the running server has not
 * actually picked up.
 *
 * pending_restart means postgresql.conf was edited, or ALTER SYSTEM was run, and
 * PostgreSQL accepted the new value into the file -- but the setting's context
 * only takes effect on the next restart, and nobody has restarted since. This is
 * silent: the file says one thing, pg_settings' current value says another, and
 * a reader trusting the file believes a change is live that never actually took.
 */
final readonly class PendingRestart implements ConfigurationRule
{
    public function inspect(Settings $settings): ?Finding
    {
        $pending = $settings->pendingRestart();

        if ($pending === []) {
            return null;
        }

        $names = implode(', ', array_keys($pending));

        return new Finding(
            rule: 'pending-restart',
            subject: 'server',
            severity: Severity::Warning,
            summary: "The value on disk for {$names} is not the value the running server is using.",
            impact: 'Somebody edited postgresql.conf or ran ALTER SYSTEM, PostgreSQL accepted the change into '
                .'the file, and the server carried on with the old value because that setting only takes effect '
                .'on the next restart. Very often the change was believed to be live months ago and never was: '
                .'nothing in the running server complains, because from its point of view nothing changed.',
            remediation: 'Restart the server to pick up the new value, or revert the file if the change was a '
                .'mistake -- either way, pg_settings.pending_restart will not clear itself.',
            query: "SELECT name, setting, boot_val, pending_restart\n"
                ."FROM pg_settings\n"
                .'WHERE pending_restart;',
        );
    }
}
