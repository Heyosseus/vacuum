<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Values\Column;
use Heyosseus\Vacuum\Values\Constraint;
use Heyosseus\Vacuum\Values\IndexDefinition;
use Heyosseus\Vacuum\Values\TableSchema;

function column(string $name, string $type = 'bigint', bool $nullable = false): Column
{
    return new Column(schema: 'public', table: 'orders', name: $name, type: $type, nullable: $nullable);
}

function constraint(string $kind, array $columns, bool $indexed = true, string $name = 'orders_pkey'): Constraint
{
    return new Constraint(
        schema: 'public',
        table: 'orders',
        name: $name,
        kind: $kind,
        columns: $columns,
        referencedTable: $kind === 'f' ? 'customers' : '',
        indexed: $indexed,
    );
}

// Not index(): Pest helper functions are global across the whole suite and the
// existing tests already declare one by that name.
function indexOn(array $columns, bool $partial = false, bool $valid = true): IndexDefinition
{
    return new IndexDefinition(
        schema: 'public',
        table: 'orders',
        name: 'orders_idx',
        columns: $columns,
        unique: false,
        valid: $valid,
        partial: $partial,
        method: 'btree',
    );
}

function schema(array $columns = [], array $constraints = [], array $indexes = []): TableSchema
{
    return new TableSchema('public', 'orders', $columns, $constraints, $indexes);
}

it('qualifies itself by schema and table', function (): void {
    expect(schema()->qualifiedName())->toBe('public.orders');
});

it('finds a column by name and returns null for one it does not have', function (): void {
    $table = schema([column('id'), column('customer_id')]);

    expect($table->column('customer_id')?->name)->toBe('customer_id')
        ->and($table->column('nothing_here'))->toBeNull();
});

it('separates the primary key from the other constraints', function (): void {
    $table = schema(constraints: [
        constraint('f', ['customer_id'], name: 'orders_customer_id_foreign'),
        constraint('p', ['id']),
    ]);

    expect($table->primaryKey()?->columns)->toBe(['id'])
        ->and($table->foreignKeys())->toHaveCount(1);
});

it('has no primary key when nothing declares one', function (): void {
    expect(schema(constraints: [constraint('u', ['email'], name: 'orders_email_unique')])->primaryKey())->toBeNull();
});

it('finds an index leading with the columns asked for', function (): void {
    $table = schema(indexes: [indexOn(['taggable_type', 'taggable_id'])]);

    expect($table->hasIndexLeadingWith('taggable_type', 'taggable_id'))->toBeTrue();
});

it('does not accept a partial index as covering a lookup', function (): void {
    // A partial index answers only for the rows its predicate admits, so it does
    // not answer the general question a rule is asking.
    $table = schema(indexes: [indexOn(['taggable_type', 'taggable_id'], partial: true)]);

    expect($table->hasIndexLeadingWith('taggable_type', 'taggable_id'))->toBeFalse();
});

it('does not accept an invalid index as covering a lookup', function (): void {
    // An invalid index is maintained by every write and may not be used by any
    // query. Counting it as coverage would hide the problem twice over.
    $table = schema(indexes: [indexOn(['taggable_type', 'taggable_id'], valid: false)]);

    expect($table->hasIndexLeadingWith('taggable_type', 'taggable_id'))->toBeFalse();
});
