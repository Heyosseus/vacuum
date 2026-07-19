<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use DateTimeImmutable;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\SettingRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Support\PostgresReleases;
use Heyosseus\Vacuum\Values\Capabilities;

/**
 * Finds a server whose major version has stopped receiving fixes, or is about to.
 *
 * PostgreSQL supports a major version for five years from its first release and
 * then stops entirely -- not merely feature freezes, but no fixes of any kind,
 * security included. A cluster past that date is on its own against every future
 * CVE. This rule is Critical once the date has passed, and Warning for the six
 * months beforehand, so there is a runway to plan the upgrade rather than a
 * single day's notice. Returns null for a major PostgresReleases does not
 * recognise: a finding this rule cannot date is a finding it should not make.
 */
final readonly class EndOfLifeMajor implements SettingRule
{
    private const int WARNING_WINDOW_DAYS = 180;

    private DateTimeImmutable $clock;

    public function __construct(?DateTimeImmutable $clock = null)
    {
        $this->clock = $clock ?? new DateTimeImmutable;
    }

    public function inspect(Capabilities $capabilities): ?Finding
    {
        $major = $capabilities->majorVersion();
        $endOfLife = PostgresReleases::endOfLife($major);

        if ($endOfLife === null) {
            return null;
        }

        $date = new DateTimeImmutable($endOfLife);
        $daysRemaining = (int) $this->clock->diff($date)->format('%r%a');

        if ($daysRemaining > self::WARNING_WINDOW_DAYS) {
            return null;
        }

        $severity = $daysRemaining < 0 ? Severity::Critical : Severity::Warning;

        $summary = $daysRemaining < 0
            ? "PostgreSQL {$major} reached end of life on {$endOfLife}."
            : "PostgreSQL {$major} reaches end of life on {$endOfLife}, {$daysRemaining} days from now.";

        return new Finding(
            rule: 'end-of-life-major',
            subject: 'server',
            severity: $severity,
            summary: $summary,
            impact: 'Once a major version reaches end of life, the PostgreSQL project stops shipping fixes for '
                .'it entirely, not merely new features -- security fixes included. A cluster still running '
                .'that major after the date is on its own against every vulnerability discovered afterwards, '
                .'with no patched release to move to short of a major upgrade.',
            remediation: 'Plan a major-version upgrade with pg_upgrade or logical replication well ahead of the '
                .'date -- unlike a minor release, a major upgrade can change the on-disk format and needs '
                .'testing against this application before it runs in production.',
        );
    }
}
