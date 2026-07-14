<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Http\Controllers;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Database\ConnectionResolver;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as Views;

final readonly class DashboardController
{
    public function __construct(
        private Advisor $advisor,
        private Capabilities $capabilities,
        private ConnectionResolver $connections,
    ) {}

    public function __invoke(): View
    {
        return Views::make('vacuum::dashboard', [
            'findings' => $this->advisor->findings(),
            'capabilities' => $this->capabilities,
            'connection' => $this->connections->resolve()->getName() ?? 'unnamed',
        ]);
    }
}
