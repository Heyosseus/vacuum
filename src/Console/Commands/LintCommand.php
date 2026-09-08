<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console\Commands;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Health;
use Heyosseus\Vacuum\Advisor\SchemaAdvisor;
use Heyosseus\Vacuum\Console\Support\FindingReporter;
use Heyosseus\Vacuum\Console\Support\SeverityBar;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Inspects the shape of the schema, and fails the build when it is wrong.
 *
 * The companion to vacuum:check, and deliberately a separate command. check
 * reads what the database has been doing -- dead tuples, bloat, a freeze age, the
 * statements it has run -- all of which need a database with a history behind it.
 * A pipeline has the opposite thing: a Postgres container ninety seconds old with
 * the migrations freshly applied, where every one of those rules finds nothing
 * and reports a perfect score on a database nobody has ever used.
 *
 * This asks the questions that are answerable there, and only those. A foreign
 * key with no index is wrong before a single row exists.
 *
 * The default bar is warning rather than critical because the two commands mean
 * different things by the word. A warning from check is a database drifting, and
 * a build should not go red because bloat grew overnight. A warning from lint is
 * a schema that was wrong the moment somebody typed it.
 */
final class LintCommand extends Command
{
    protected $signature = 'vacuum:lint
        {--fail-on=warning : The lowest severity that should fail the command: critical, warning, info or never}
        {--format=text : text for a person, json for anything else}
        {--baseline= : Path to a baseline file}
        {--generate-baseline : Write the baseline and exit}
        {--no-baseline : Ignore any baseline that exists}';

    protected $description = 'Inspect the schema for defects that are visible without any data';

    public function handle(
        SchemaAdvisor $advisor,
        Repository $config,
        FindingReporter $reporter,
    ): int {
        // A gate that goes green because it never looked is worse than no gate.
        if ($config->get('vacuum.enabled') !== true) {
            $this->components->error(
                'Vacuum is disabled, so nothing was inspected. Set VACUUM_ENABLED=true to lint this schema.',
            );

            return self::INVALID;
        }

        if ($this->baselineRequested()) {
            $this->components->error(
                'Baselines are not in this release yet. Run without --baseline, --generate-baseline or '
                    .'--no-baseline, or pin an earlier expectation of them.',
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
                'Every table has a key, every foreign key has an index, and every type lines up.',
            );
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Whether the caller asked for a baseline. The options are declared now so
     * that the command's signature does not change when the feature lands;
     * accepting them silently in the meantime would be worse than refusing them.
     */
    private function baselineRequested(): bool
    {
        if ($this->option('generate-baseline') === true) {
            return true;
        }

        if ($this->option('no-baseline') === true) {
            return true;
        }

        return is_string($this->option('baseline')) && $this->option('baseline') !== '';
    }

    /**
     * The document a pipeline parses.
     *
     * Separate from vacuum:check's, and carries `table` where that one does not:
     * a schema finding is always about a table, and the thing reading this is
     * going to want to say which one.
     *
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
                'evidence' => $finding->evidence,
                'table' => $finding->table,
            ], $findings),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
