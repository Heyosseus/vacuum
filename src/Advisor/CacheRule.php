<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

use Heyosseus\Vacuum\Values\CacheStatistic;

interface CacheRule
{
    public function inspect(CacheStatistic $cache): ?Finding;
}
