<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Http\Controllers;

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Illuminate\Http\JsonResponse;

final readonly class DashboardController
{
    public function __construct(private Advisor $advisor) {}

    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'findings' => array_map(
                static fn (Finding $finding): array => $finding->toArray(),
                $this->advisor->findings(),
            ),
        ]);
    }
}
