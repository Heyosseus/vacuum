<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\UnindexedForeignKey;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Constraint;
use Heyosseus\Vacuum\Values\TableSchema;

function foreignKey(string $column, bool $indexed, string $references = 'customers'): Constraint
{
    return new Constraint(
        schema: 'public',
        table: 'orders',
        name: 'orders_'.$column.'_foreign',
        kind: 'f',
        columns: [$column],
        referencedTable: $references,
        indexed: $indexed,
    );
}

function orders(Constraint ...$constraints): TableSchema
{
    return new TableSchema('public', 'orders', [], array_values($constraints), []);
}

it('reports a foreign key with no index behind it', function (): void {
    $findings = app(UnindexedForeignKey::class)->inspect(orders(foreignKey('customer_id', indexed: false)));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('unindexed-foreign-key')
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->subject)->toBe('public.orders.customer_id')
        ->and($findings[0]->table)->toBe('public.orders');
});

it('says nothing about a foreign key that is indexed', function (): void {
    expect(app(UnindexedForeignKey::class)->inspect(orders(foreignKey('customer_id', indexed: true))))->toBe([]);
});

it('reports each unindexed key on a table separately', function (): void {
    // Four unindexed keys are four problems on four columns, each fixed by a
    // different statement. One finding carrying four of them would be unusable
    // as an annotation.
    $findings = app(UnindexedForeignKey::class)->inspect(orders(
        foreignKey('customer_id', indexed: false),
        foreignKey('warehouse_id', indexed: false, references: 'warehouses'),
    ));

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->subject)->toBe('public.orders.customer_id')
        ->and($findings[1]->subject)->toBe('public.orders.warehouse_id');
});

it('ignores primary keys and unique constraints', function (): void {
    $primary = new Constraint(
        schema: 'public', table: 'orders', name: 'orders_pkey',
        kind: 'p', columns: ['id'], referencedTable: '', indexed: true,
    );

    expect(app(UnindexedForeignKey::class)->inspect(orders($primary)))->toBe([]);
});

it('offers the CREATE INDEX that would fix it', function (): void {
    $findings = app(UnindexedForeignKey::class)->inspect(orders(foreignKey('customer_id', indexed: false)));

    expect($findings[0]->remediation)
        ->toBe('CREATE INDEX CONCURRENTLY ON "public"."orders" ("customer_id");');
});

it('names the parent whose deletes are paying for it', function (): void {
    $findings = app(UnindexedForeignKey::class)->inspect(orders(foreignKey('customer_id', indexed: false)));

    expect($findings[0]->impact)->toContain('customers')
        ->and($findings[0]->impact)->toContain('sequential');
});

it('reports a composite key under its leading column', function (): void {
    $composite = new Constraint(
        schema: 'public', table: 'orders', name: 'orders_composite_foreign',
        kind: 'f', columns: ['tenant_id', 'customer_id'], referencedTable: 'customers', indexed: false,
    );

    $findings = app(UnindexedForeignKey::class)->inspect(orders($composite));

    expect($findings[0]->subject)->toBe('public.orders.tenant_id')
        ->and($findings[0]->evidence)->toContain('tenant_id, customer_id');
});

it('says nothing about a foreign key with no columns', function (): void {
    // Not reachable from the catalog, but the rule indexes into the column list
    // and must not do so blindly.
    $empty = new Constraint(
        schema: 'public', table: 'orders', name: 'orders_empty',
        kind: 'f', columns: [], referencedTable: 'customers', indexed: false,
    );

    expect(app(UnindexedForeignKey::class)->inspect(orders($empty)))->toBe([]);
});
