<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Concerns;

use Heyosseus\Vacuum\Vacuum;

/**
 * The one gate, on a widget.
 *
 * Vacuum's widgets are registered as global Livewire components, so each is a door
 * in its own right rather than a piece of the Overview page that arranges them.
 * Authorization used to come only from that page, which is the container asking on
 * the widget's behalf — fine until a widget is mounted by anything else.
 *
 * This is defense in depth rather than a hole being closed: Livewire will not mount
 * a component without a signed snapshot, so there was no easy way through. But a
 * surface that reads the server's own catalogs should answer for itself, and it
 * answers with the same Vacuum::auth callback every other Vacuum surface asks. One
 * callback still governs who may look.
 */
trait GatedWidget
{
    public static function canView(): bool
    {
        return Vacuum::check(request());
    }
}
