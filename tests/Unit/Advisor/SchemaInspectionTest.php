<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Inspections\SchemaInspection;
use Heyosseus\Vacuum\Advisor\SchemaRule;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Queries\Schemas;
use Heyosseus\Vacuum\Values\TableSchema;

function twoTables(): Schemas
{
    return new class implements Schemas
    {
        /** @return list<TableSchema> */
        public function all(): array
        {
            return [
                new TableSchema('public', 'orders', [], [], []),
                new TableSchema('public', 'customers', [], [], []),
            ];
        }
    };
}

function ruleFinding(int $count): SchemaRule
{
    return new readonly class($count) implements SchemaRule
    {
        public function __construct(private int $count) {}

        public function inspect(TableSchema $table): array
        {
            $findings = [];

            for ($i = 0; $i < $this->count; $i++) {
                $findings[] = new Finding(
                    rule: 'stub',
                    subject: $table->qualifiedName().'.'.$i,
                    severity: Severity::Warning,
                    summary: 'stub',
                    impact: 'stub',
                );
            }

            return $findings;
        }
    };
}

it('puts every rule to every table and flattens what they return', function (): void {
    // Two tables, one rule returning two findings each: four findings, not two.
    // A schema rule may find several problems on one table, which is why this
    // contract returns a list where the others return one finding or null.
    $findings = (new SchemaInspection(twoTables(), [ruleFinding(2)]))->findings();

    expect($findings)->toHaveCount(4)
        ->and($findings[0]->subject)->toBe('public.orders.0')
        ->and($findings[3]->subject)->toBe('public.customers.1');
});

it('says nothing when no rule finds anything', function (): void {
    expect((new SchemaInspection(twoTables(), [ruleFinding(0)]))->findings())->toBe([]);
});
