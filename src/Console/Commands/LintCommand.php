<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console\Commands;

use Composer\InstalledVersions;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Health;
use Heyosseus\Vacuum\Advisor\SchemaAdvisor;
use Heyosseus\Vacuum\Console\Support\Baseline;
use Heyosseus\Vacuum\Console\Support\FindingReporter;
use Heyosseus\Vacuum\Console\Support\GithubReporter;
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
        {--format=text : text for a person, json for a pipeline, github for pull request annotations}
        {--baseline= : Path to a baseline file}
        {--generate-baseline : Write every current finding to the baseline and exit}
        {--no-baseline : Ignore any baseline that exists}';

    protected $description = 'Inspect the schema for defects that are visible without any data';

    public function handle(
        SchemaAdvisor $advisor,
        Repository $config,
        FindingReporter $reporter,
        GithubReporter $github,
    ): int {
        // A gate that goes green because it never looked is worse than no gate.
        if ($config->get('vacuum.enabled') !== true) {
            $this->components->error(
                'Vacuum is disabled, so nothing was inspected. Set VACUUM_ENABLED=true to lint this schema.',
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

        if ($this->option('generate-baseline') === true) {
            $path = $this->baselinePath($config);
            $document = Baseline::record($findings)->encode($this->version());

            // A read-only checkout, a --baseline= pointing at a directory that does
            // not exist, or a full disk all fail file_put_contents() the same way:
            // silently, unless the return value is actually checked.
            if (@file_put_contents($path, $document) !== strlen($document)) {
                $this->components->error("Could not write the baseline to {$path}.");

                return self::FAILURE;
            }

            $this->components->info(
                count($findings).' findings written to '.$path.'. Commit it, and vacuum:lint will '
                    .'report only what is new from now on.',
            );

            return self::SUCCESS;
        }

        $baseline = $this->baseline($config);
        $kept = [];
        $suppressed = 0;

        foreach ($findings as $finding) {
            if ($baseline->suppresses($finding)) {
                $suppressed++;

                continue;
            }

            $kept[] = $finding;
        }

        // Stale entries are shown -- the reader needs to know the baseline wants
        // pruning -- but never fed to the bar. They are Info because the defect
        // being gone is good news, and good news must never be able to redden a
        // build at --fail-on=info; fixing something is not a regression.
        $display = [...$kept, ...$baseline->stale($findings)];

        $health = Health::from($display);
        $failed = $bar->fails($kept);

        $format = $this->option('format');

        if ($format === 'json') {
            $this->output->writeln($this->json($health, $display, $failed, $suppressed));
        } elseif ($format === 'github') {
            $github->report($this->output, $display, $suppressed);
            $this->appendStepSummary($github->summary($display));
        } else {
            $reporter->report(
                $this->output,
                $health,
                $display,
                'Every table has a key, every foreign key has an index, and every type lines up.',
            );

            if ($suppressed > 0) {
                $this->line("  <fg=gray>{$suppressed} suppressed by the baseline.</>");
                $this->newLine();
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The baseline in force, which is none when the caller said so or when there
     * is no file to read.
     */
    private function baseline(Repository $config): Baseline
    {
        if ($this->option('no-baseline') === true) {
            return Baseline::none();
        }

        $path = $this->baselinePath($config);

        if (! is_file($path)) {
            return Baseline::none();
        }

        $contents = file_get_contents($path);

        return $contents === false ? Baseline::none() : Baseline::decode($contents);
    }

    private function baselinePath(Repository $config): string
    {
        $option = $this->option('baseline');

        if (is_string($option) && $option !== '') {
            return base_path($option);
        }

        $configured = $config->get('vacuum.lint.baseline');

        return base_path(is_string($configured) ? $configured : 'vacuum-baseline.json');
    }

    /**
     * GitHub gives a job a file to append a summary to, and gives it only inside
     * Actions -- so the variable's presence is the feature flag, and no
     * configuration key is needed for something the runner either offers or does
     * not.
     *
     * A failure here does not fail the command. The step summary is a convenience
     * the runner offers on top of the annotations that already carry every
     * finding; unlike the baseline, losing it loses nothing the caller depended
     * on. It only must not be mistaken for having worked, so a failed append is
     * reported rather than swallowed.
     */
    private function appendStepSummary(string $markdown): void
    {
        $path = getenv('GITHUB_STEP_SUMMARY');

        if (! is_string($path) || $path === '') {
            return;
        }

        if (@file_put_contents($path, $markdown, FILE_APPEND) !== strlen($markdown)) {
            $this->components->warn("Could not append the step summary to {$path}.");
        }
    }

    /**
     * Stamped into the file so a reader knows which rule set produced it.
     *
     * Vacuum's own version, not the application's. In every real installation the
     * application is the root package, and Vacuum is a dependency underneath it --
     * reading the root would stamp the baseline with the app's own tag, or with
     * "dev-main" for a checkout, which says nothing about which rule set wrote the
     * file. Composer is asked for "heyosseus/vacuum" by name first, so the
     * baseline carries Vacuum's own version wherever Composer can report one.
     *
     * The root package is the fallback, for whenever Composer cannot answer that
     * lookup -- which includes this package's own test suite, where Vacuum *is*
     * the root and the root's version is the only one there is to report.
     */
    private function version(): string
    {
        if (InstalledVersions::isInstalled('heyosseus/vacuum')) {
            $version = InstalledVersions::getPrettyVersion('heyosseus/vacuum');

            if ($version !== null) {
                return $version;
            }
        }

        /** @var array{pretty_version: string} $root */
        $root = InstalledVersions::getRootPackage();

        return $root['pretty_version'];
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
    private function json(Health $health, array $findings, bool $failed, int $suppressed): string
    {
        return json_encode([
            'score' => $health->score,
            'grade' => $health->grade->value,
            'failed' => $failed,
            'suppressed' => $suppressed,
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
