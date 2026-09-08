<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\TableSchemas;
use Heyosseus\Vacuum\Values\TableSchema;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS lint_orders CASCADE');
    DB::statement('DROP TABLE IF EXISTS lint_customers CASCADE');
    DB::statement('CREATE TABLE lint_customers (id bigserial PRIMARY KEY, payload json)');
    DB::statement('CREATE TABLE lint_orders (id bigserial PRIMARY KEY, customer_id bigint REFERENCES lint_customers (id))');
    DB::statement('CREATE INDEX lint_orders_customer_id_index ON lint_orders (customer_id)');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS lint_orders CASCADE');
    DB::statement('DROP TABLE IF EXISTS lint_customers CASCADE');
});

function assembled(string $table): ?TableSchema
{
    foreach (app(TableSchemas::class)->all() as $schema) {
        if ($schema->table === $table) {
            return $schema;
        }
    }

    return null;
}

it('brings a table its columns, constraints and indexes together', function (): void {
    $orders = assembled('lint_orders');

    expect($orders?->column('customer_id')?->type)->toBe('bigint')
        ->and($orders?->primaryKey()?->columns)->toBe(['id'])
        ->and($orders?->foreignKeys())->toHaveCount(1)
        ->and($orders?->hasIndexLeadingWith('customer_id'))->toBeTrue();
});

it('gives a table with no constraints of its own an empty list rather than nothing', function (): void {
    // A table without a foreign key must not be missing from the set, or a rule
    // that counts tables would silently see fewer than exist.
    $customers = assembled('lint_customers');

    expect($customers)->not->toBeNull()
        ->and($customers?->foreignKeys())->toBe([]);
});
