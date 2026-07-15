<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Install;

/**
 * What became of an attempt to register the plugin in a panel provider. The
 * installer turns each of these into what it says and whether it needs to fall
 * back to printing instructions.
 */
enum InstallOutcome
{
    /** The plugin was added and the file still parses. */
    case Wired;

    /** The file already registered the plugin; nothing was changed. */
    case AlreadyRegistered;

    /** The file was not a shape the registrar could edit; nothing was changed. */
    case Unrecognised;

    /** The edit was made, did not parse, and the original was restored. */
    case SyntaxRestored;
}
