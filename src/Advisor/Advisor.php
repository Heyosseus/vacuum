<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

use Heyosseus\Vacuum\Values\TableStatistic;

/**
 * Puts every registered rule to every table and collects what comes back.
 *
 * The advisor judges statistics it is handed rather than fetching them itself:
 * a query class knows how to ask PostgreSQL a question, and the advisor knows
 * what the answer means. Keeping the two apart is what lets the rules be tested
 * without a database.
 */
final readonly class Advisor
{
    /**
     * @param  iterable<TableRule>  $rules
     */
    public function __construct(private iterable $rules) {}

    /**
     * @param  list<TableStatistic>  $tables
     * @return list<Finding>
     */
    public function inspect(array $tables): array
    {
        $findings = [];

        foreach ($tables as $table) {
            foreach ($this->rules as $rule) {
                $finding = $rule->inspect($table);

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        usort(
            $findings,
            static fn (Finding $a, Finding $b): int => $a->severity->rank() <=> $b->severity->rank(),
        );

        return $findings;
    }
}
