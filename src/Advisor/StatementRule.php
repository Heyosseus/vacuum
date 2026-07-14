<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

use Heyosseus\Vacuum\Values\Statement;

interface StatementRule
{
    public function inspect(Statement $statement): ?Finding;
}
