<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Console\Support;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Schema\MigrationMap;
use Heyosseus\Vacuum\Schema\SourceLocation;
use Illuminate\Console\OutputStyle;

/**
 * Renders findings as GitHub Actions workflow commands.
 *
 * A build log is read when the build is already red and somebody is already
 * annoyed. An annotation is read on the diff, by every reviewer, as a matter of
 * course -- so this is the difference between a tool a team runs and a tool a
 * team notices.
 *
 * The escaping is the part that breaks if it is not tested. A workflow command
 * is line-oriented and delimited by commas and colons, and a remediation is
 * multi-line SQL while a summary can easily contain a comma -- so an unescaped
 * implementation mangles precisely the findings people most want to read, and
 * does it silently.
 *
 * The file GitHub is handed matters as much as the escaping does. GitHub attaches
 * an annotation to the diff by matching `file` against a path relative to the
 * repository root; an absolute one -- which is what the map's own paths are, on
 * purpose, for every other consumer -- never matches anything, and the annotation
 * silently fails to appear on the pull request instead of erroring.
 */
final readonly class GithubReporter
{
    public function __construct(private MigrationMap $map) {}

    /**
     * @param  list<Finding>  $findings
     */
    public function report(OutputStyle $output, array $findings, int $suppressed = 0): void
    {
        foreach ($findings as $finding) {
            $properties = ['title' => $finding->rule];
            $location = $this->map->locate($finding);

            if ($location instanceof SourceLocation) {
                $properties = ['file' => $this->relative($location->file), 'line' => (string) $location->line] + $properties;
            }

            $pairs = [];

            foreach ($properties as $key => $value) {
                $pairs[] = $key.'='.$this->property($value);
            }

            $output->writeln(
                '::'.$this->level($finding->severity).' '.implode(',', $pairs)
                    .'::'.$this->message($finding->subject.' — '.$finding->summary),
            );
        }

        // The one place a pull request most needs this number: four hundred
        // findings suppressed by the baseline is exactly the fact a reviewer
        // reading only the diff's annotations would otherwise never see.
        if ($suppressed > 0) {
            $output->writeln('::notice::'.$this->message("{$suppressed} suppressed by the baseline."));
        }
    }

    /**
     * @param  list<Finding>  $findings
     */
    public function summary(array $findings): string
    {
        $rows = ['| Severity | Rule | Subject |', '| --- | --- | --- |'];

        foreach ($findings as $finding) {
            $rows[] = '| '.$finding->severity->value.' | '.$finding->rule.' | `'.$finding->subject.'` |';
        }

        return implode("\n", $rows)."\n";
    }

    private function level(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical => 'error',
            Severity::Warning => 'warning',
            Severity::Info, Severity::Unknown => 'notice',
        };
    }

    /**
     * The percent sign is escaped first, or it would escape the escapes.
     */
    private function message(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private function property(string $value): string
    {
        return str_replace([':', ','], ['%3A', '%2C'], $this->message($value));
    }

    /**
     * The path GitHub can actually match against the diff.
     *
     * The map's paths are absolute, correctly, for every other consumer -- but
     * GitHub resolves an annotation's `file` against the repository root, not the
     * runner's filesystem, so `/home/runner/work/app/app/database/migrations/…`
     * matches nothing and the annotation attaches to no line at all. A path
     * outside base_path() -- a migrations directory configured elsewhere, or a
     * test fixture -- is left exactly as the map produced it rather than mangled
     * into something that looks relative but is not.
     */
    private function relative(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return str_starts_with($normalized, $base) ? substr($normalized, strlen($base)) : $file;
    }
}
