<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console\Support;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Health;
use Heyosseus\Vacuum\Advisor\Severity;
use Illuminate\Console\OutputStyle;

/**
 * Renders findings for a person: the score, the list worst first, and what each
 * rule cost. Shared by vacuum:check and vacuum:lint so that the two commands
 * cannot drift into printing the same information two different ways.
 */
final readonly class FindingReporter
{
    /**
     * @param  list<Finding>  $findings
     */
    public function report(OutputStyle $output, Health $health, array $findings, string $clean): void
    {
        $output->newLine();
        $output->writeln("  <options=bold>{$health->score}</> / 100   Grade {$health->grade->value}");

        if ($findings === []) {
            $output->newLine();
            $output->writeln("  <fg=green>Nothing to report.</> {$clean}");
            $output->newLine();

            return;
        }

        $output->newLine();

        foreach ($findings as $finding) {
            $severity = str_pad($finding->severity->value, 8);

            $output->writeln(
                "  <fg={$this->colour($finding->severity)};options=bold>{$severity}</>"
                    ." <options=bold>{$finding->subject}</>  <fg=gray>{$finding->rule}</>",
            );

            $output->writeln("           {$finding->summary}");

            if ($finding->remediation !== null) {
                // Printed, never run. Vacuum has no code path that writes to the
                // database it inspects, and a command that offered to fix things for
                // you would be the first.
                foreach (explode("\n", $finding->remediation) as $line) {
                    $output->writeln("           <fg=gray>{$line}</>");
                }
            }

            $output->newLine();
        }

        foreach ($health->deductions as $rule => $cost) {
            $output->writeln('  <fg=gray>'.str_pad($rule, 24, '.').' -'.$cost.'</>');
        }

        $output->newLine();
    }

    private function colour(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical => 'red',
            Severity::Warning => 'yellow',
            Severity::Info, Severity::Unknown => 'blue',
        };
    }
}
