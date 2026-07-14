<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Vacuum;
use Illuminate\Http\Request;

it('has no console at all until the application asks for one', function (): void {
    // The console is off by default, and off means the route does not exist. A
    // console that merely refuses to run things needs only one bug to run them.
    Vacuum::auth(static fn (Request $request): bool => true);

    $this->get('/vacuum/console')->assertNotFound();
    $this->post('/vacuum/console', ['statement' => 'SELECT 1'])->assertNotFound();
});
