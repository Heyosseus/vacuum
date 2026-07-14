<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

use Heyosseus\Vacuum\Values\TableStatistic;

/**
 * A question worth asking of a single table. It answers with a Finding, or with
 * null when it has nothing to say — which is the answer for most tables, most
 * of the time.
 */
interface TableRule
{
    public function inspect(TableStatistic $table): ?Finding;
}
