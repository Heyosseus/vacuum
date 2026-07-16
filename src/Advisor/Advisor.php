<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

use Throwable;

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
            try {
                foreach ($inspection->findings() as $finding) {
                    $findings[] = $finding;
                }
            } catch (Throwable $failure) {
                $findings[] = $this->failed($inspection, $failure);
            }
        }

        usort(
            $findings,
            static fn (Finding $a, Finding $b): int => $a->severity->rank() <=> $b->severity->rank(),
        );

        return $findings;
    }

    /**
     * A broken inspection becomes a finding rather than a broken dashboard.
     *
     * Every surface is fed from this one merge, so a single inspection throwing
     * -- a privilege the role lost, a view an upgrade took away -- would
     * otherwise take every panel down with it. The failure is reported in the
     * very list it failed to contribute to, and the inspections that worked
     * keep their say.
     */
    private function failed(Inspection $inspection, Throwable $failure): Finding
    {
        $inspectionName = basename(str_replace('\\', '/', $inspection::class));

        return new Finding(
            rule: 'inspection-failed',
            subject: $inspectionName,
            severity: Severity::Info,
            summary: "{$inspectionName} could not inspect the database, so its panel has no data.",
            impact: $failure->getMessage(),
        );
    }
}
