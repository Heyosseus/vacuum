<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

/**
 * One subject, examined: a query paired with the rules that judge what it returns.
 *
 * This is the seam the package extends along. Indexes, sessions, locks and
 * settings each become an inspection of their own, and the advisor learns nothing
 * new to accommodate them -- it merges whatever it is given. The alternative, one
 * rule interface taking a snapshot of the entire server, would couple every rule
 * to every query and force the whole collection to run to evaluate any of it.
 */
interface Inspection
{
    /**
     * @return list<Finding>
     *
     * @api Public API. Its shape is covered by the package version from 1.0 onward.
     */
    public function findings(): array;
}
