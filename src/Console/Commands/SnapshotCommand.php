<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console\Commands;

use Heyosseus\Vacuum\History\Recorder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Records one point in Vacuum's history, for a scheduler to call on a cadence.
 *
 * It refuses when there is nothing true to record. A snapshot taken while Vacuum is
 * switched off would capture an empty database and no findings, and every trend
 * built on it afterwards — a cache-hit delta, a bloat slope, a wraparound forecast —
 * would be drawn partly through a zero that never happened. A gap in the history is
 * honest; a false point in it is not.
 */
final class SnapshotCommand extends Command
{
    protected $signature = 'vacuum:snapshot';

    protected $description = 'Record a health snapshot into Vacuum\'s history';

    public function handle(Recorder $recorder, Repository $config): int
    {
        if ($config->get('vacuum.enabled') !== true) {
            $this->components->error(
                'Vacuum is disabled, so there is nothing to record. Set VACUUM_ENABLED=true to snapshot this database.',
            );

            return self::INVALID;
        }

        if ($config->get('vacuum.history.enabled') !== true) {
            $this->components->error(
                'Vacuum history is off. Set VACUUM_HISTORY_ENABLED=true and run the migration before recording snapshots.',
            );

            return self::INVALID;
        }

        $snapshot = $recorder->record();

        $this->components->info(
            "Snapshot recorded: {$snapshot->health_score}/100, grade {$snapshot->grade}, "
                ."{$snapshot->findings()->count()} findings.",
        );

        return self::SUCCESS;
    }
}
