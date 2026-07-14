<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console\Commands;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Health;
use Heyosseus\Vacuum\Advisor\Severity;
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

    public function handle(Advisor $advisor, Repository $config): int
    {
        // The master switch means Vacuum runs no queries, so it would find nothing,
        // and a gate that goes green because it never looked is worse than no gate.
        // This is the one case where an empty result is not a pass.
        if ($config->get('vacuum.enabled') !== true) {
            $this->components->error(
                'Vacuum is disabled, so nothing was inspected. Set VACUUM_ENABLED=true to check this database.',
            );

            return self::INVALID;
        }

        $bar = $this->option('fail-on');

        if (! is_string($bar) || ! $this->recognised($bar)) {
            $this->components->error(
                "There is no severity called '".(is_string($bar) ? $bar : '')."'. "
                    .'Use critical, warning, info or never.',
            );

            return self::INVALID;
        }

        $findings = $advisor->findings();
        $health = Health::from($findings);
        $failed = $this->worthFailing($findings, $bar);

        if ($this->option('format') === 'json') {
            $this->output->writeln($this->json($health, $findings, $failed));
        } else {
            $this->report($health, $findings);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function report(Health $health, array $findings): void
    {
        $this->newLine();
        $this->line("  <options=bold>{$health->score}</> / 100   Grade {$health->grade->value}");

        if ($findings === []) {
            $this->newLine();
            $this->line('  <fg=green>Nothing to report.</> Every table, index and session is inside its thresholds.');
            $this->newLine();

            return;
        }

        $this->newLine();

        foreach ($findings as $finding) {
            $severity = str_pad($finding->severity->value, 8);

            $this->line(
                "  <fg={$this->colour($finding->severity)};options=bold>{$severity}</>"
                    ." <options=bold>{$finding->subject}</>  <fg=gray>{$finding->rule}</>",
            );

            $this->line("           {$finding->summary}");

            if ($finding->remediation !== null) {
                // Printed, never run. Vacuum has no code path that writes to the
                // database it inspects, and a command that offered to fix things for
                // you would be the first.
                foreach (explode("\n", $finding->remediation) as $line) {
                    $this->line("           <fg=gray>{$line}</>");
                }
            }

            $this->newLine();
        }

        foreach ($health->deductions as $rule => $cost) {
            $this->line('  <fg=gray>'.str_pad($rule, 24, '.').' -'.$cost.'</>');
        }

        $this->newLine();
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

    /**
     * Whether anything found is at or above the bar the caller set.
     *
     * The default bar is critical, so an info finding -- a fact rather than a fault,
     * the server saying what it cannot see -- fails nothing unless somebody puts the
     * bar there on purpose.
     *
     * @param  list<Finding>  $findings
     */
    private function worthFailing(array $findings, string $bar): bool
    {
        if ($bar === 'never') {
            return false;
        }

        $limit = Severity::from($bar);

        foreach ($findings as $finding) {
            if ($finding->severity->rank() <= $limit->rank()) {
                return true;
            }
        }

        return false;
    }

    private function recognised(string $bar): bool
    {
        return $bar === 'never' || Severity::tryFrom($bar) instanceof Severity;
    }

    private function colour(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical => 'red',
            Severity::Warning => 'yellow',
            Severity::Info => 'blue',
        };
    }
}
