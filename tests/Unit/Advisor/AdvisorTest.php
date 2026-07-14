<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Advisor\TableRule;
use Heyosseus\Vacuum\Values\TableStatistic;

function subject(string $name): TableStatistic
{
    return new TableStatistic(
        schema: 'public',
        name: $name,
        liveTuples: 0,
        deadTuples: 0,
        modificationsSinceAnalyze: 0,
        lastVacuum: null,
        lastAutovacuum: null,
        lastAnalyze: null,
        lastAutoanalyze: null,
    );
}

/**
 * A rule that always fires, at a severity of the test's choosing.
 */
function alwaysFires(string $rule, Severity $severity): TableRule
{
    return new class($rule, $severity) implements TableRule
    {
        public function __construct(private readonly string $rule, private readonly Severity $severity) {}

        public function inspect(TableStatistic $table): Finding
        {
            return new Finding(
                rule: $this->rule,
                subject: $table->qualifiedName(),
                severity: $this->severity,
                summary: 'summary',
                impact: 'impact',
            );
        }
    };
}

function neverFires(): TableRule
{
    return new class implements TableRule
    {
        public function inspect(TableStatistic $table): ?Finding
        {
            return null;
        }
    };
}

it('has nothing to report when no rule fires', function (): void {
    $advisor = new Advisor([neverFires(), neverFires()]);

    expect($advisor->inspect([subject('widgets'), subject('gadgets')]))->toBe([]);
});

it('asks every rule about every table', function (): void {
    $advisor = new Advisor([
        alwaysFires('first', Severity::Warning),
        alwaysFires('second', Severity::Warning),
    ]);

    $findings = $advisor->inspect([subject('widgets'), subject('gadgets')]);

    expect($findings)->toHaveCount(4);
});

it('puts the findings that matter most first', function (): void {
    $advisor = new Advisor([
        alwaysFires('noted', Severity::Info),
        alwaysFires('urgent', Severity::Critical),
        alwaysFires('untidy', Severity::Warning),
    ]);

    $findings = $advisor->inspect([subject('widgets')]);

    expect(array_column($findings, 'rule'))->toBe(['urgent', 'untidy', 'noted']);
});

it('keeps a quiet rule from hiding a loud one', function (): void {
    $advisor = new Advisor([neverFires(), alwaysFires('urgent', Severity::Critical)]);

    $findings = $advisor->inspect([subject('widgets')]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('urgent');
});
