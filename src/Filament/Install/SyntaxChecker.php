<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Install;

/**
 * Whether a PHP file parses. The installer asks this after editing a panel
 * provider, so that a broken edit is caught and rolled back rather than shipped.
 */
interface SyntaxChecker
{
    public function check(string $file): bool;
}
