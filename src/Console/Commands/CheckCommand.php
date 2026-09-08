<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console\Commands;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Health;
use Heyosseus\Vacuum\Console\Support\FindingReporter;
use Heyosseus\Vacuum\Console\Support\SeverityBar;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Runs the advisor from a terminal, and fails the build when it should.
 *
 * The dashboard only tells you something if you remember to open it. This puts the
 * same rules in your pipeline: a migration that ships a duplicate index fails the
 * build, and a staging database drifting toward wraparound fails the nightly job,
 * whether or not anybody was looking.
 *
 * It never writes. The remediation is printed for a person to read and decide on,
 * exactly as it is on the page, and this command has no more power over the database
 * than the dashboard does.
 */
final class CheckCommand extends Command
{
    protected $signature = 'vacuum:check
        {--fail-on=critical : The lowest severity that should fail the command: critical, warning, info or never}
        {--format=text : text for a person, json for anything else}';

    protected $description = 'Inspect the database and fail if the advisor finds something serious';

    public function handle(
        Advisor $advisor,
        Repository $config,
        FindingReporter $reporter,
    ): int {
        // The master switch means Vacuum runs no queries, so it would find nothing,
        // and a gate that goes green because it never looked is worse than no gate.
        // This is the one case where an empty result is not a pass.
        if ($config->get('vacuum.enabled') !== true) {
            $this->components->error(
                'Vacuum is disabled, so nothing was inspected. Set VACUUM_ENABLED=true to check this database.',
            );

            return self::INVALID;
        }

        $option = $this->option('fail-on');
        $bar = SeverityBar::parse(is_string($option) ? $option : null);

        if (! $bar instanceof SeverityBar) {
            $this->components->error(
                "There is no severity called '".(is_string($option) ? $option : '')."'. "
                    .'Use critical, warning, info or never.',
            );

            return self::INVALID;
        }

        $findings = $advisor->findings();
        $health = Health::from($findings);
        $failed = $bar->fails($findings);

        if ($this->option('format') === 'json') {
            $this->output->writeln($this->json($health, $findings, $failed));
        } else {
            $reporter->report(
                $this->output,
                $health,
                $findings,
                'Every table, index and session is inside its thresholds.',
            );
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function json(Health $health, array $findings, bool $failed): string
    {
        return json_encode([
            'score' => $health->score,
            'grade' => $health->grade->value,
            'failed' => $failed,
            'deductions' => $health->deductions,
            'findings' => array_map(static fn (Finding $finding): array => [
                'rule' => $finding->rule,
                'subject' => $finding->subject,
                'severity' => $finding->severity->value,
                'summary' => $finding->summary,
                'impact' => $finding->impact,
                'remediation' => $finding->remediation,
                'query' => $finding->query,
            ], $findings),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
