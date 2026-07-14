<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Inspections;

use Heyosseus\Vacuum\Advisor\BloatRule;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Queries\BloatEstimates;

/**
 * Puts every bloat rule to every table the estimate could be trusted for.
 */
final readonly class BloatInspection implements Inspection
{
    /**
     * @param  iterable<BloatRule>  $rules
     */
    public function __construct(
        private BloatEstimates $estimates,
        private iterable $rules,
    ) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        $findings = [];

        foreach ($this->estimates->all() as $estimate) {
            foreach ($this->rules as $rule) {
                $finding = $rule->inspect($estimate);

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }
}
