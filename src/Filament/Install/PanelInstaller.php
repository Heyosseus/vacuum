<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Install;

/**
 * Registers Vacuum's plugin in one panel provider as a single, reversible step.
 *
 * The registrar decides the edit; this decides whether it is safe to keep. The
 * edit is written to a temporary file beside the provider, that file is handed to
 * PHP to parse, and only if it parses does it replace the original -- by rename,
 * which on a POSIX filesystem is atomic. So the provider is never a half-written
 * file, not for an instant, and there is no window in which an interrupted install
 * leaves the application unable to boot.
 *
 * This used to work the other way round: copy the original to a .bak, write over
 * the original, lint it, and copy the backup back if the lint failed. Every step
 * of that discards a return value, and each one fails differently and silently. A
 * backup that could not be written still reported a successful rollback while
 * restoring nothing. A write that could not happen -- a read-only file is enough --
 * left the original untouched, which of course parsed, and the method reported the
 * panel wired when nothing had changed. And on a full disk a truncated backup
 * would be copied over a perfectly good provider. Writing somewhere else first and
 * moving it into place once has none of those failure modes, because there is only
 * one moment when the provider changes and it either happens or it does not.
 */
final readonly class PanelInstaller
{
    public function __construct(
        private PluginRegistrar $registrar,
        private SyntaxChecker $syntax,
    ) {}

    public function install(string $file): InstallOutcome
    {
        $original = @file_get_contents($file);

        if ($original === false) {
            return InstallOutcome::Failed;
        }

        $edited = $this->registrar->inject($original);

        if ($edited === null) {
            return InstallOutcome::Unrecognised;
        }

        if ($edited === $original) {
            return InstallOutcome::AlreadyRegistered;
        }

        $pending = $file.'.vacuum-pending';

        try {
            // Every one of these is checked. The whole class of bug being fixed
            // here is a filesystem call whose failure was assumed away.
            if (@file_put_contents($pending, $edited) !== strlen($edited)) {
                return InstallOutcome::Failed;
            }

            if (! $this->syntax->check($pending)) {
                return InstallOutcome::SyntaxRejected;
            }

            // The provider is only ever replaced whole. A failure here has
            // changed nothing, which is why it can be reported as plainly as it
            // is: the file on disk is still the one that was there before.
            if (! @rename($pending, $file)) {
                return InstallOutcome::Failed;
            }

            return InstallOutcome::Wired;
        } finally {
            // A successful rename has already consumed the pending file; every
            // other path leaves one behind, including an interrupt, and none of
            // them should leave a copy of somebody's provider sitting next to it.
            if (is_file($pending)) {
                @unlink($pending);
            }
        }
    }
}
