<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

/**
 * Everything the package believes is wrong with the database, worst first.
 *
 * The advisor knows nothing about tables, bloat, indexes or sessions. It merges
 * what the inspections found and orders it by how much it matters, which is the
 * only judgement it is qualified to make.
 */
final readonly class Advisor
{
    /**
     * @param  iterable<Inspection>  $inspections
     */
    public function __construct(private iterable $inspections) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        $findings = [];

        foreach ($this->inspections as $inspection) {
            foreach ($inspection->findings() as $finding) {
                $findings[] = $finding;
            }
        }

        usort(
            $findings,
            static fn (Finding $a, Finding $b): int => $a->severity->rank() <=> $b->severity->rank(),
        );

        return $findings;
    }
}
