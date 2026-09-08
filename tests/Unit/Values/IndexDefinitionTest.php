<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Values\IndexDefinition;

function definition(array $columns = ['taggable_type', 'taggable_id']): IndexDefinition
{
    return new IndexDefinition(
        schema: 'public',
        table: 'taggables',
        name: 'taggables_taggable_type_taggable_id_index',
        columns: $columns,
        unique: false,
        valid: true,
        partial: false,
        method: 'btree',
    );
}

it('qualifies the index by its schema, not its table', function (): void {
    // An index name is unique within a schema, not within a table, and the
    // qualified name is what a DROP statement has to spell.
    expect(definition()->qualifiedName())->toBe('public.taggables_taggable_type_taggable_id_index');
});

it('leads with the columns it starts with, in order', function (): void {
    expect(definition()->leadsWith('taggable_type', 'taggable_id'))->toBeTrue()
        ->and(definition()->leadsWith('taggable_type'))->toBeTrue();
});

it('does not lead with columns it merely contains', function (): void {
    // An index on (a, b) cannot serve a lookup on b alone. Set membership is the
    // wrong question and answering it would be the bug this method exists to avoid.
    expect(definition()->leadsWith('taggable_id'))->toBeFalse()
        ->and(definition()->leadsWith('taggable_id', 'taggable_type'))->toBeFalse();
});

it('cannot lead with more columns than it has', function (): void {
    expect(definition(['taggable_type'])->leadsWith('taggable_type', 'taggable_id'))->toBeFalse();
});

it('leads with nothing when asked for nothing', function (): void {
    expect(definition()->leadsWith())->toBeTrue();
});
