<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Vacuum;
use Illuminate\Http\Request;

it('serves no blade routes when the ui is handed to filament', function (): void {
    // The Filament plugin owns the UI in this mode, so the standalone dashboard
    // must not answer at its own URL -- two doors into the same rooms is one door
    // too many to keep authorized.
    Vacuum::auth(static fn (Request $request): bool => true);

    $this->get('/vacuum')->assertNotFound();
});
