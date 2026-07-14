<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Inspections;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Advisor\TableRule;
use Heyosseus\Vacuum\Queries\TableStatistics;

/**
 * Puts every table rule to every table PostgreSQL keeps statistics for.
 */
final readonly class TableInspection implements Inspection
{
    /**
     * @param  iterable<TableRule>  $rules
     */
    public function __construct(
        private TableStatistics $tables,
        private iterable $rules,
    ) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        $findings = [];

        foreach ($this->tables->all() as $table) {
            foreach ($this->rules as $rule) {
                $finding = $rule->inspect($table);

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }
}
