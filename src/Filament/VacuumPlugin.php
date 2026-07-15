<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Heyosseus\Vacuum\Filament\Resources\TableResource;

/**
 * Vacuum, added to a Filament panel. The installer splices
 * `->plugin(VacuumPlugin::make())` into a panel provider; this is what that call
 * builds. It registers Vacuum's surfaces on the panel and nothing else -- the
 * authorization is the resource's own, so that one Vacuum::auth callback still
 * governs who may look, whether they arrive through Blade or through Filament.
 *
 * Only the Tables surface exists in this slice; the Dashboard and Console pages
 * are registered here in later ones.
 */
final class VacuumPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'vacuum';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            TableResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
