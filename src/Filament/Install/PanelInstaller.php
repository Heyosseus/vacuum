<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Install;

/**
 * Registers Vacuum's plugin in one panel provider as a single, reversible step.
 *
 * The registrar decides the edit; this decides whether it is safe to keep. A backup
 * is taken before the write and the result is handed to PHP to parse. Only a file
 * that parses is left in place; anything else is rolled back to the original. Either
 * way no backup is left lying next to the provider.
 */
final readonly class PanelInstaller
{
    public function __construct(
        private PluginRegistrar $registrar,
        private SyntaxChecker $syntax,
    ) {}

    public function install(string $file): InstallOutcome
    {
        $original = (string) file_get_contents($file);
        $edited = $this->registrar->inject($original);

        if ($edited === null) {
            return InstallOutcome::Unrecognised;
        }

        if ($edited === $original) {
            return InstallOutcome::AlreadyRegistered;
        }

        $backup = $file.'.bak';
        copy($file, $backup);
        file_put_contents($file, $edited);

        if (! $this->syntax->check($file)) {
            copy($backup, $file);
            unlink($backup);

            return InstallOutcome::SyntaxRestored;
        }

        unlink($backup);

        return InstallOutcome::Wired;
    }
}
