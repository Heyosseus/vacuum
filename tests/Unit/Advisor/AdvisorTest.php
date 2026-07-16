<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspection;
use Heyosseus\Vacuum\Advisor\Severity;
use RuntimeException;

/** An inspection the way production breaks one: the query throws, not the rule. */
final readonly class UnreadableStatisticsInspection implements Inspection
{
    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        throw new RuntimeException('permission denied for view pg_stat_activity');
    }
}

function finding(string $rule, Severity $severity): Finding
{
    return new Finding(
        rule: $rule,
        subject: 'public.widgets',
        severity: $severity,
        summary: 'summary',
        impact: 'impact',
    );
}

/**
 * @param  list<Finding>  $findings
 */
function inspection(array $findings): Inspection
{
    return new readonly class($findings) implements Inspection
    {
        /**
         * @param  list<Finding>  $findings
         */
        public function __construct(private array $findings) {}

        /**
         * @return list<Finding>
         */
        public function findings(): array
        {
            return $this->findings;
        }
    };
}

it('has nothing to report when no inspection finds anything', function (): void {
    $advisor = new Advisor([inspection([]), inspection([])]);

    expect($advisor->findings())->toBe([]);
});

it('gathers what every inspection found', function (): void {
    $advisor = new Advisor([
        inspection([finding('dead-tuples', Severity::Warning)]),
        inspection([finding('table-bloat', Severity::Warning)]),
    ]);

    expect(array_column($advisor->findings(), 'rule'))->toBe(['dead-tuples', 'table-bloat']);
});

it('puts the findings that matter most first, whichever inspection made them', function (): void {
    $advisor = new Advisor([
        inspection([finding('noted', Severity::Info)]),
        inspection([finding('urgent', Severity::Critical)]),
        inspection([finding('untidy', Severity::Warning)]),
    ]);

    expect(array_column($advisor->findings(), 'rule'))->toBe(['urgent', 'untidy', 'noted']);
});

it('keeps a quiet inspection from hiding a loud one', function (): void {
    $advisor = new Advisor([inspection([]), inspection([finding('urgent', Severity::Critical)])]);

    expect(array_column($advisor->findings(), 'rule'))->toBe(['urgent']);
});

it('keeps one broken inspection from taking every panel down with it', function (): void {
    $advisor = new Advisor([
        new UnreadableStatisticsInspection,
        inspection([finding('urgent', Severity::Critical)]),
    ]);

    $findings = $advisor->findings();

    // The failure is a finding, sorted like any other, so the inspections that
    // worked keep their say and the reader is told what went dark and why.
    expect(array_column($findings, 'rule'))->toBe(['urgent', 'inspection-failed'])
        ->and($findings[1]->severity)->toBe(Severity::Info)
        ->and($findings[1]->subject)->toBe('UnreadableStatisticsInspection')
        ->and($findings[1]->summary)->toContain('no data')
        ->and($findings[1]->impact)->toBe('permission denied for view pg_stat_activity');
});

it('reports every broken inspection, not merely the first', function (): void {
    $advisor = new Advisor([new UnreadableStatisticsInspection, new UnreadableStatisticsInspection]);

    expect(array_column($advisor->findings(), 'rule'))->toBe(['inspection-failed', 'inspection-failed']);
});
