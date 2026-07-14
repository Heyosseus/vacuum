<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

use Heyosseus\Vacuum\Values\IndexStatistic;

interface IndexRule
{
    public function inspect(IndexStatistic $index): ?Finding;
}
