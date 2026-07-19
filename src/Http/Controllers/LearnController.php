<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Http\Controllers;

use Heyosseus\Vacuum\Database\ConnectionResolver;
use Heyosseus\Vacuum\Learn\Curriculum;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as Views;

/**
 * The index of the Learn section: every registered lesson, grouped into the
 * tier that teaches it.
 *
 * Nothing here touches the inspected database. The tiers come from the
 * container's own registration order, so a lesson appears on this page by
 * being tagged and by nothing else, and a reader reaches the observations
 * only once they have chosen one.
 */
final readonly class LearnController
{
    public function __construct(
        private Curriculum $curriculum,
        private Capabilities $capabilities,
        private ConnectionResolver $connections,
    ) {}

    public function __invoke(): View
    {
        return Views::make('vacuum::learn.index', [
            'tiers' => $this->curriculum->tree(),
            'lessons' => $this->curriculum->all(),
            'capabilities' => $this->capabilities,
            'connection' => $this->connections->resolve()->getName() ?? 'unnamed',
        ]);
    }
}
