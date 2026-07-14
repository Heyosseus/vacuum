<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Inspections;

use Heyosseus\Vacuum\Advisor\DuplicateRule;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Queries\IndexDuplicates;

/**
 * Puts every duplicate rule to each index PostgreSQL found a copy of.
 */
final readonly class DuplicateInspection implements Inspection
{
    /**
     * @param  iterable<DuplicateRule>  $rules
     */
    public function __construct(
        private IndexDuplicates $duplicates,
        private iterable $rules,
    ) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        $findings = [];

        foreach ($this->duplicates->all() as $duplicate) {
            foreach ($this->rules as $rule) {
                $finding = $rule->inspect($duplicate);

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }
}
