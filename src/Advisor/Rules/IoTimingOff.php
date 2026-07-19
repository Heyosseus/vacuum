<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Rules;

use Heyosseus\Vacuum\Advisor\ConfigurationRule;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Settings;

/**
 * Notes that every I/O timing figure the server reports is a silent zero rather
 * than a measured one.
 *
 * With track_io_timing off, every I/O timing column in pg_stat_database,
 * pg_stat_io, pg_stat_statements and the output of EXPLAIN (BUFFERS) reads zero.
 * That zero is not evidence the server is fast; it is evidence nobody asked. A
 * reader looking at those columns without knowing this setting is off can easily
 * conclude the server is not I/O bound when nothing was ever measured. This is
 * Info rather than Warning: it costs nothing on its own, it only makes other
 * evidence untrustworthy.
 */
final readonly class IoTimingOff implements ConfigurationRule
{
    public function inspect(Settings $settings): ?Finding
    {
        if (! $settings->isOff('track_io_timing')) {
            return null;
        }

        return new Finding(
            rule: 'io-timing-off',
            subject: 'server',
            severity: Severity::Info,
            summary: 'track_io_timing is off, so nothing on this server measures how long reads and writes take.',
            impact: 'Every I/O timing column in pg_stat_database, pg_stat_io and pg_stat_statements, and every '
                .'buffer timing line EXPLAIN (BUFFERS) would otherwise print, reads zero. That is a silent zero, '
                .'not a measured one -- a reader looking at those numbers can conclude the server is not I/O '
                .'bound when in fact nobody ever measured it.',
            remediation: "ALTER SYSTEM SET track_io_timing = on;\nSELECT pg_reload_conf();",
            query: "SELECT name, setting\n"
                ."FROM pg_settings\n"
                ."WHERE name = 'track_io_timing';",
        );
    }
}
