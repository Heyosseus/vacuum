<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\ConfigurationRule;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Settings;

/**
 * Finds a server with no bound at all on how long a statement, a lock wait, or an
 * idle-in-transaction session may run.
 *
 * All three default to 0, meaning disabled, and a server that has never set any
 * of them is running on the default. With none set, one abandoned transaction --
 * a connection left open by a crashed worker, a debugger paused on a breakpoint --
 * pins the xmin horizon and blocks vacuum from reclaiming dead rows across every
 * table in the cluster, not merely the ones that transaction touched.
 */
final readonly class TimeoutsUnset implements ConfigurationRule
{
    public function inspect(Settings $settings): ?Finding
    {
        $names = ['statement_timeout', 'lock_timeout', 'idle_in_transaction_session_timeout'];

        foreach ($names as $name) {
            if ($settings->value($name) !== '0') {
                return null;
            }
        }

        return new Finding(
            rule: 'timeouts-unset',
            subject: 'server',
            severity: Severity::Warning,
            summary: 'statement_timeout, lock_timeout and idle_in_transaction_session_timeout are all disabled.',
            impact: 'Nothing bounds how long a statement may run, how long a session may wait on a lock, or how '
                .'long a transaction may sit idle while holding one open. One abandoned transaction -- a crashed '
                .'worker, a debugger paused on a breakpoint, a connection a pool never returned -- pins the xmin '
                .'horizon and blocks vacuum from reclaiming dead rows across every table in the cluster, not just '
                .'the ones that transaction touched.',
            remediation: 'Set these per role rather than in postgresql.conf, which applies to every session '
                ."including PostgreSQL's own maintenance connections:\n"
                ."ALTER ROLE app SET statement_timeout = '15s';\n"
                ."ALTER ROLE app SET lock_timeout = '5s';\n"
                ."ALTER ROLE app SET idle_in_transaction_session_timeout = '30s';",
            query: "SELECT name, setting\n"
                ."FROM pg_settings\n"
                ."WHERE name IN ('statement_timeout', 'lock_timeout', 'idle_in_transaction_session_timeout');",
        );
    }
}
