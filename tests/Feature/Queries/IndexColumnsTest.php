<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\IndexColumns;
use Heyosseus\Vacuum\Values\IndexDefinition;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS lint_indexes CASCADE');
    DB::statement('CREATE TABLE lint_indexes (id bigserial PRIMARY KEY, kind text, label text, note text)');
    DB::statement('CREATE INDEX lint_indexes_pair ON lint_indexes (kind, label)');
    DB::statement('CREATE INDEX lint_indexes_partial ON lint_indexes (note) WHERE note IS NOT NULL');
    DB::statement('CREATE INDEX lint_indexes_expression ON lint_indexes (lower(label))');
    DB::statement('CREATE INDEX lint_indexes_include ON lint_indexes (kind) INCLUDE (note)');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS lint_indexes CASCADE');
});

function found(string $name): ?IndexDefinition
{
    foreach (app(IndexColumns::class)->all() as $index) {
        if ($index->name === $name) {
            return $index;
        }
    }

    return null;
}

it('reports key columns in index order', function (): void {
    expect(found('lint_indexes_pair')?->columns)->toBe(['kind', 'label']);
});

it('marks a partial index as partial', function (): void {
    // A partial index serves only the rows its predicate admits, so a rule must
    // not accept it as covering a general lookup.
    expect(found('lint_indexes_partial')?->partial)->toBeTrue()
        ->and(found('lint_indexes_pair')?->partial)->toBeFalse();
});

it('excludes an index over an expression', function (): void {
    // Its indkey carries a 0 where the expression is, and a column list with a
    // silent gap in it would mislead every rule that reads this.
    expect(found('lint_indexes_expression'))->toBeNull();
});

it('excludes an INCLUDE payload from the key columns', function (): void {
    // The payload is stored in the leaf and cannot be searched, so it is not part
    // of what the index can serve.
    expect(found('lint_indexes_include')?->columns)->toBe(['kind']);
});

it('reports the primary key index as unique and valid', function (): void {
    expect(found('lint_indexes_pkey')?->unique)->toBeTrue()
        ->and(found('lint_indexes_pkey')?->valid)->toBeTrue()
        ->and(found('lint_indexes_pkey')?->method)->toBe('btree');
});
