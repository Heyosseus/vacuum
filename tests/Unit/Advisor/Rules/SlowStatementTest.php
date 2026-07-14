<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\SlowStatement;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Statement;

function statement(float $mean, int $calls = 100, string $sql = 'SELECT * FROM orders WHERE id = $1'): Statement
{
    return new Statement(
        queryId: '-8203847362',
        sql: $sql,
        calls: $calls,
        totalMilliseconds: $mean * $calls,
        meanMilliseconds: $mean,
        rows: $calls,
    );
}

beforeEach(function (): void {
    config()->set('vacuum.thresholds.slow_query_milliseconds', 500);
});

it('says nothing about a statement that returns promptly', function (): void {
    expect(app(SlowStatement::class)->inspect(statement(mean: 12.0)))->toBeNull();
});

it('reports a statement that takes longer than it should, on average', function (): void {
    $finding = app(SlowStatement::class)->inspect(statement(mean: 800.0));

    expect($finding?->rule)->toBe('slow-statement')
        ->and($finding?->severity)->toBe(Severity::Warning)
        ->and($finding?->summary)->toContain('800')
        ->and($finding?->summary)->toContain('100 calls');
});

it('raises its voice at a statement taking many times too long', function (): void {
    expect(app(SlowStatement::class)->inspect(statement(mean: 3_000.0))?->severity)
        ->toBe(Severity::Critical);
});

it('shows the statement rather than describing it', function (): void {
    $finding = app(SlowStatement::class)->inspect(statement(mean: 800.0));

    expect($finding?->evidence)->toBe('SELECT * FROM orders WHERE id = $1');
});

it('offers nothing to run, because the statement it found cannot be run', function (): void {
    // pg_stat_statements normalises the parameters away: what it stores is a shape,
    // not a query. Handing somebody an EXPLAIN of it would hand them a syntax error.
    $finding = app(SlowStatement::class)->inspect(statement(mean: 800.0));

    expect($finding?->remediation)->toBeNull()
        ->and($finding?->impact)->toContain('EXPLAIN')
        ->and($finding?->impact)->toContain('average');
});
