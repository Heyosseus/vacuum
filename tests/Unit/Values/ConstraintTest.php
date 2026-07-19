<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Values\Constraint;

it('names the table the constraint is on', function (): void {
    $constraint = new Constraint(
        schema: 'public',
        table: 'orders',
        name: 'orders_customer_id_foreign',
        kind: 'f',
        columns: ['customer_id'],
        referencedTable: 'customers',
        indexed: false,
    );

    expect($constraint->qualifiedName())->toBe('public.orders');
});

it('tells a foreign key apart from the kinds PostgreSQL indexes for you', function (): void {
    // The distinction the Learn section is built on: PostgreSQL creates an index
    // for a primary key and for a unique constraint, and none for a foreign key.
    $foreign = new Constraint(
        schema: 'public',
        table: 'orders',
        name: 'orders_customer_id_foreign',
        kind: 'f',
        columns: ['customer_id'],
        referencedTable: 'customers',
        indexed: false,
    );

    $primary = new Constraint(
        schema: 'public',
        table: 'orders',
        name: 'orders_pkey',
        kind: 'p',
        columns: ['id'],
        referencedTable: '',
        indexed: true,
    );

    expect($foreign->isForeignKey())->toBeTrue()
        ->and($primary->isForeignKey())->toBeFalse();
});
