<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Heyosseus\Vacuum\Filament\VacuumPlugin;

it('identifies itself and has nothing left to do at boot', function (): void {
    // Registration happens in register(); boot() is deliberately empty. Running it
    // against the real test panel proves it stays a harmless no-op.
    $plugin = VacuumPlugin::make();

    $plugin->boot(Filament::getPanel('admin'));

    expect($plugin->getId())->toBe('vacuum');
});
