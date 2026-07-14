<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Inspections;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\IndexRule;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Queries\IndexStatistics;

/**
 * Puts every index rule to every index in the database.
 */
final readonly class IndexInspection implements Inspection
{
    /**
     * @param  iterable<IndexRule>  $rules
     */
    public function __construct(
        private IndexStatistics $indexes,
        private iterable $rules,
    ) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        $findings = [];

        foreach ($this->indexes->all() as $index) {
            foreach ($this->rules as $rule) {
                $finding = $rule->inspect($index);

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }
}
