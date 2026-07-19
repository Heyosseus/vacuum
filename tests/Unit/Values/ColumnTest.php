<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Values\Column;

it('names the table it belongs to rather than itself', function (): void {
    // A column's qualified name is its table's, because that is what a lesson
    // needs to say: "your orders table has a deleted_at" is the sentence, not
    // "your orders.deleted_at exists".
    $column = new Column(
        schema: 'public',
        table: 'orders',
        name: 'deleted_at',
        type: 'timestamp without time zone',
        nullable: true,
    );

    expect($column->qualifiedName())->toBe('public.orders');
});

it('carries the type as psql would render it', function (): void {
    $column = new Column(
        schema: 'public',
        table: 'orders',
        name: 'payload',
        type: 'jsonb',
        nullable: false,
    );

    expect($column->type)->toBe('jsonb')
        ->and($column->nullable)->toBeFalse();
});
