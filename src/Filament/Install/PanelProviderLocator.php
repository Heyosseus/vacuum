<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Install;

/**
 * Finds the Filament panel providers an application has, by their conventional
 * name. The installer needs this to tell the three cases apart: no panel to add
 * to, exactly one, or several to choose between.
 */
final class PanelProviderLocator
{
    /**
     * The panel provider files in the given directory, as absolute paths, sorted so
     * that "the one panel" and "the first of several" are the same across runs.
     *
     * @return list<string>
     */
    public function all(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        // glob returns false only on a system-level failure; treated the same as
        // "found none", so there is no separate untestable path to reason about.
        $matches = glob(rtrim($directory, '/\\').'/*PanelProvider.php') ?: [];

        sort($matches);

        return $matches;
    }
}
