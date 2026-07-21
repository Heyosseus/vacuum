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

    /**
     * The edit was made in a temporary file, did not parse, and was discarded.
     * The provider was never written to.
     *
     * Named for what happened rather than for a rollback, because there is no
     * longer anything to roll back: nothing reaches the provider until it has
     * already parsed.
     */
    case SyntaxRejected;

    /**
     * The filesystem refused somewhere -- the provider could not be read, the
     * temporary file could not be written, or it could not be moved into place.
     * Nothing was changed.
     *
     * This case exists because its absence was a bug. Every filesystem call here
     * used to have its return value discarded, so an install that could not write
     * anything at all reported success and sent the reader off to configure a UI
     * that was never wired up.
     */
    case Failed;
}
