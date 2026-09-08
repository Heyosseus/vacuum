<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Inspections;

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Advisor\SchemaRule;
use Heyosseus\Vacuum\Queries\Schemas;

/**
 * Puts every schema rule to every table in the database.
 *
 * The same shape as every other inspection, differing only in flattening: a
 * schema rule answers with a list rather than with one finding, because one
 * table can be wrong in several places at once.
 */
final readonly class SchemaInspection implements Inspection
{
    /**
     * @param  iterable<SchemaRule>  $rules
     */
    public function __construct(
        private Schemas $schemas,
        private iterable $rules,
    ) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        $findings = [];

        foreach ($this->schemas->all() as $schema) {
            foreach ($this->rules as $rule) {
                foreach ($rule->inspect($schema) as $finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }
}
