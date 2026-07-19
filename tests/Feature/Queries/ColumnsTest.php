<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\Columns;
use Heyosseus\Vacuum\Values\Column;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('drop table if exists learn_columns cascade');
    DB::statement('create table learn_columns (
        id bigserial primary key,
        deleted_at timestamp null,
        payload jsonb not null,
        gone text
    )');
    DB::statement('alter table learn_columns drop column gone');
});

afterEach(function (): void {
    DB::statement('drop table if exists learn_columns cascade');
});

function columnsOf(string $table): array
{
    return array_values(array_filter(
        app(Columns::class)->all(),
        static fn (Column $c): bool => $c->table === $table,
    ));
}

it('reads a table columns with their types and nullability', function (): void {
    $named = [];

    foreach (columnsOf('learn_columns') as $column) {
        $named[$column->name] = $column;
    }

    expect($named)->toHaveKeys(['id', 'deleted_at', 'payload'])
        ->and($named['payload']->type)->toBe('jsonb')
        ->and($named['deleted_at']->nullable)->toBeTrue()
        ->and($named['payload']->nullable)->toBeFalse();
});

it('does not report a dropped column as a column', function (): void {
    $names = array_map(static fn (Column $c): string => $c->name, columnsOf('learn_columns'));

    expect($names)->not->toContain('gone');
});
