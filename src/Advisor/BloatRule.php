<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

use Heyosseus\Vacuum\Values\BloatEstimate;

/**
 * A rule that judges how much space a table is wasting.
 *
 * A separate interface from TableRule on purpose: a rule should be handed the one
 * thing it reasons about, so that adding a rule never widens what has to be
 * queried before it can run.
 */
interface BloatRule
{
    public function inspect(BloatEstimate $table): ?Finding;
}
