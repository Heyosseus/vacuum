<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

use Heyosseus\Vacuum\Values\IndexStatistic;

/**
 * A question worth asking of a single index. It answers with a Finding, or with null
 * when it has nothing to say, which is the answer most of the time.
 *
 * @api Public API. Its shape is covered by the package version from 1.0 onward.
 */
interface IndexRule
{
    public function inspect(IndexStatistic $index): ?Finding;
}
