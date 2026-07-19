<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\SettingRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Support\PostgresReleases;
use Heyosseus\Vacuum\Values\Capabilities;

/**
 * Finds a server running an older minor release than PostgreSQL has shipped for
 * its major version.
 *
 * A version-only check like this one is imperfect in three specific ways, and
 * the finding says so rather than overstating what it can prove:
 *
 *   1. Distributions -- Debian and RHEL among them -- backport security fixes
 *      into their packages without changing the version string a client sees,
 *      so this can over-report on a server that is, in fact, already patched.
 *   2. Several recent CVEs are gated on contrib extensions such as pgcrypto,
 *      intarray and pg_trgm; whether they apply here depends on whether those
 *      extensions are installed, not merely on the server version.
 *   3. Some CVEs live in libpq, the client library, rather than in the server,
 *      and are invisible to a check that only ever looks inside the database.
 *
 * Returns null for an unrecognised major: reporting a finding this rule cannot
 * substantiate would be worse than saying nothing.
 */
final readonly class UnpatchedServer implements SettingRule
{
    public function inspect(Capabilities $capabilities): ?Finding
    {
        $major = $capabilities->majorVersion();
        $latestMinor = PostgresReleases::latestMinor($major);

        if ($latestMinor === null) {
            return null;
        }

        $minor = $capabilities->serverVersion - ($major * 10_000);

        if ($minor >= $latestMinor) {
            return null;
        }

        return new Finding(
            rule: 'unpatched-server',
            subject: 'server',
            severity: Severity::Warning,
            summary: "This server is running PostgreSQL {$major}.{$minor}; the latest release for that major "
                ."is {$major}.{$latestMinor}.",
            impact: 'Every minor release between this one and the latest carries fixes, some of them for '
                .'security issues, that this server has not received. That said, a version number alone cannot '
                .'prove exposure, for three reasons: distributions such as Debian and RHEL backport security '
                .'fixes into their packages without changing the version string, so this can over-report on a '
                .'server that is already patched; several recent CVEs are gated on contrib extensions such as '
                .'pgcrypto, intarray and pg_trgm, and only apply when one of them is installed; and some CVEs '
                .'live in libpq, the client library, entirely outside what a check run from inside the database '
                .'can see.',
            remediation: 'Upgrade to the latest minor release for this major -- a minor upgrade replaces the '
                .'binaries and restarts the server, but never touches the on-disk format the way a major '
                .'upgrade does.',
        );
    }
}
