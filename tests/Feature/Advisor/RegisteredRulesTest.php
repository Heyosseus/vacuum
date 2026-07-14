<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Advisor\TableRule;
use Heyosseus\Vacuum\Values\TableStatistic;

function bloated(): TableStatistic
{
    return new TableStatistic(
        schema: 'public',
        name: 'widgets',
        liveTuples: 40_000,
        deadTuples: 60_000,
        modificationsSinceAnalyze: 0,
        lastVacuum: null,
        lastAutovacuum: null,
        lastAnalyze: null,
        lastAutoanalyze: null,
    );
}

it('resolves an advisor already carrying the packaged rules', function (): void {
    $findings = app(Advisor::class)->inspect([bloated()]);

    expect(array_column($findings, 'rule'))->toContain('dead-tuples');
});

it('lets an application add a rule of its own', function (): void {
    app()->tag([HouseRule::class], 'vacuum.table-rules');

    $findings = app(Advisor::class)->inspect([bloated()]);

    expect(array_column($findings, 'rule'))->toContain('house-rule');
});

final class HouseRule implements TableRule
{
    public function inspect(TableStatistic $table): Finding
    {
        return new Finding(
            rule: 'house-rule',
            subject: $table->qualifiedName(),
            severity: Severity::Info,
            summary: 'summary',
            impact: 'impact',
        );
    }
}
