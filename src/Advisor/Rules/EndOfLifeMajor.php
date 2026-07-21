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
 * single day's notice.
 *
 * A major newer than the release table returns null -- a finding this rule cannot
 * date is a finding it should not make, and being wrong about a server that came
 * out after this package did is the wrong way round to be wrong. A major older
 * than the table's floor is the opposite case: the date is unknown because
 * nobody has needed it in years, which is itself the finding.
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

        if (PostgresReleases::isBelowSupportFloor($major)) {
            return $this->longDead($major);
        }

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

    /**
     * A major so old the release table does not carry its date.
     *
     * No number is quoted, because none is known here and inventing one would be
     * the same mistake in the other direction. That the date is unfindable is
     * the point being made.
     */
    private function longDead(int $major): Finding
    {
        return new Finding(
            rule: 'end-of-life-major',
            subject: 'server',
            severity: Severity::Critical,
            summary: "PostgreSQL {$major} has been out of support for years.",
            impact: 'This major stopped receiving fixes of any kind, security included, long enough ago that '
                .'the PostgreSQL project no longer lists it among the versions it dates. Every vulnerability '
                .'found since then is unpatched here and will stay that way: there is no minor release to '
                .'move to, only a major upgrade.',
            remediation: 'Plan a major-version upgrade with pg_upgrade or logical replication. From a major '
                .'this old the jump is large enough that it is worth testing against a copy of this database '
                .'first -- the on-disk format, the default configuration and several deprecated behaviours '
                .'have all changed in between.',
        );
    }
}
