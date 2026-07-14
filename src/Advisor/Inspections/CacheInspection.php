<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor\Inspections;

use Heyosseus\Vacuum\Advisor\CacheRule;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Queries\CacheStatistics;
use Heyosseus\Vacuum\Values\Capabilities;

/**
 * Puts every cache rule to the database as a whole.
 *
 * The first inspection that has to ask what the server is willing to tell it.
 * With track_counts off, every counter reads zero, and a hit ratio computed from
 * zeroes is a flawless one: the panel would report a perfect cache on a server
 * that is measuring nothing. So it says that instead.
 */
final readonly class CacheInspection implements Inspection
{
    /**
     * @param  iterable<CacheRule>  $rules
     */
    public function __construct(
        private Capabilities $capabilities,
        private CacheStatistics $cache,
        private iterable $rules,
    ) {}

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        if (! $this->capabilities->enabled('track_counts')) {
            return [$this->notCounting()];
        }

        $statistic = $this->cache->read();

        $findings = [];

        foreach ($this->rules as $rule) {
            $finding = $rule->inspect($statistic);

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function notCounting(): Finding
    {
        return new Finding(
            rule: 'statistics-disabled',
            subject: 'database',
            severity: Severity::Info,
            summary: 'PostgreSQL is not counting block reads, so the cache hit ratio cannot be measured.',
            impact: 'With track_counts off, the statistics collector records nothing, and every counter this '
                .'dashboard reads is a zero rather than a measurement. Autovacuum also relies on these '
                .'counters to decide what to vacuum, so it is not deciding much either.',
            remediation: 'ALTER SYSTEM SET track_counts = on;',
        );
    }
}
