<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Install;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Asks PHP itself whether a file parses, by running `php -l` on it. Using the same
 * engine that will later run the file is the only check that cannot disagree with
 * it: a home-grown parser might bless something PHP rejects, and that is exactly the
 * file the installer must not leave behind.
 */
final class PhpLintChecker implements SyntaxChecker
{
    public function check(string $file): bool
    {
        $php = (new PhpExecutableFinder)->find() ?: 'php';

        $process = new Process([$php, '-l', $file]);
        $process->run();

        return $process->isSuccessful();
    }
}
