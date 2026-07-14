<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Vacuum;
use Illuminate\Http\Request;

it('serves nothing at all once the master switch is off', function (): void {
    // Even to somebody the application would otherwise have let in: a switch
    // that only hides the dashboard from strangers is not a switch.
    Vacuum::auth(static fn (Request $request): bool => true);

    $this->get('/vacuum')->assertNotFound();
});
