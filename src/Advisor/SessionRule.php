<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

use Heyosseus\Vacuum\Values\Session;

interface SessionRule
{
    public function inspect(Session $session): ?Finding;
}
