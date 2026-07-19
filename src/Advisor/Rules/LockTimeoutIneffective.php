<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\ConfigurationRule;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Settings;

/**
 * Finds a lock_timeout that can never actually fire.
 *
 * PostgreSQL's own documentation says it plainly: if statement_timeout is nonzero,
 * setting lock_timeout to the same value or larger is pointless, because the
 * statement timeout always fires first and cancels the whole statement before the
 * lock wait ever reaches its own limit. The setting is not merely redundant --
 * it is dead configuration that looks like a safety net and is not one.
 */
final readonly class LockTimeoutIneffective implements ConfigurationRule
{
    public function inspect(Settings $settings): ?Finding
    {
        $statementTimeout = $settings->integer('statement_timeout');
        $lockTimeout = $settings->integer('lock_timeout');

        if ($statementTimeout === null || $lockTimeout === null) {
            return null;
        }

        if ($statementTimeout === 0 || $lockTimeout === 0) {
            return null;
        }

        if ($lockTimeout < $statementTimeout) {
            return null;
        }

        return new Finding(
            rule: 'lock-timeout-ineffective',
            subject: 'server',
            severity: Severity::Warning,
            summary: 'lock_timeout is set to the same value as statement_timeout, or larger, so it never gets '
                .'the chance to fire.',
            impact: 'statement_timeout always cancels the statement first, because it is measured from the same '
                .'starting point and its limit is reached no later than lock_timeout\'s. lock_timeout has been '
                .'configured to look like it bounds how long a session waits on a lock, but it is dead: nothing '
                .'a person reading postgresql.conf would notice, and nothing that changes behaviour today.',
            remediation: 'lock_timeout must be strictly smaller than statement_timeout for it to do anything. '
                ."ALTER ROLE app SET lock_timeout = '5s'; -- with statement_timeout well above it",
            query: "SELECT name, setting\n"
                ."FROM pg_settings\n"
                ."WHERE name IN ('statement_timeout', 'lock_timeout');",
        );
    }
}
