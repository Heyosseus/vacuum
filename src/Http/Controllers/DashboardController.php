<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Http\Controllers;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Health;
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
        // The score is worked out from the very findings shown beneath it, so the
        // two cannot contradict each other.
        $findings = $this->advisor->findings();

        return Views::make('vacuum::dashboard', [
            'findings' => $findings,
            'health' => Health::from($findings),
            'capabilities' => $this->capabilities,
            'connection' => $this->connections->resolve()->getName() ?? 'unnamed',
        ]);
    }
}
