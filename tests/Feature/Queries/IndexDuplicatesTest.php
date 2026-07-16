<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\IndexDuplicates;
use Heyosseus\Vacuum\Values\IndexDuplicate;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS pallets');
    DB::statement('CREATE TABLE pallets (id serial PRIMARY KEY, label text, depot_id int)');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS pallets');
});

/**
 * @return list<IndexDuplicate>
 */
function duplicates(): array
{
    return app(IndexDuplicates::class)->all();
}

function duplicate(string $name): ?IndexDuplicate
{
    return collect(duplicates())
        ->firstWhere(fn (IndexDuplicate $duplicate): bool => $duplicate->name === $name);
}

it('finds an index that is an exact copy of another', function (): void {
    DB::statement('CREATE INDEX pallets_label_index ON pallets (label)');
    DB::statement('CREATE INDEX pallets_label_again ON pallets (label)');

    $found = collect(duplicates())->filter(
        fn (IndexDuplicate $duplicate): bool => $duplicate->table === 'pallets',
    );

    // One of the pair is reported, and it points at the other. Which of the two is
    // kept is decided by the query, but exactly one of them is.
    expect($found)->toHaveCount(1)
        ->and($found->first()->duplicateOf)->not->toBe($found->first()->name)
        ->and($found->first()->definition)->toContain('btree (label)');
});

it('keeps the constraint and reports the plain index', function (): void {
    // Given a unique index and an ordinary one over the same column, the unique
    // one is doing a second job. Telling somebody to drop that one would be telling
    // them to drop a rule the database is enforcing.
    DB::statement('CREATE UNIQUE INDEX pallets_label_unique ON pallets (label)');
    DB::statement('CREATE INDEX pallets_label_index ON pallets (label)');

    expect(duplicate('pallets_label_index'))->not->toBeNull()
        ->and(duplicate('pallets_label_index')->duplicateOf)->toBe('pallets_label_unique')
        ->and(duplicate('pallets_label_unique'))->toBeNull();
});

it('does not call an index on different columns a duplicate', function (): void {
    DB::statement('CREATE INDEX pallets_label_index ON pallets (label)');
    DB::statement('CREATE INDEX pallets_depot_index ON pallets (depot_id)');

    expect(duplicates())->toBe([]);
});

it('does not call a reversed column order a duplicate', function (): void {
    // (label, depot_id) and (depot_id, label) answer different questions, and
    // PostgreSQL treats them as different indexes. So does this.
    DB::statement('CREATE INDEX pallets_one ON pallets (label, depot_id)');
    DB::statement('CREATE INDEX pallets_two ON pallets (depot_id, label)');

    expect(duplicates())->toBe([]);
});

it('does not call a prefix of a wider index a duplicate', function (): void {
    // (label) is redundant against (label, depot_id) for lookups on label, but it
    // is smaller, and there are workloads where keeping both is right. Vacuum only
    // reports what it is certain about.
    DB::statement('CREATE INDEX pallets_label_index ON pallets (label)');
    DB::statement('CREATE INDEX pallets_wide_index ON pallets (label, depot_id)');

    expect(duplicates())->toBe([]);
});

it('does not call two partial indexes with different predicates duplicates', function (): void {
    DB::statement('CREATE INDEX pallets_shipped ON pallets (label) WHERE depot_id IS NULL');
    DB::statement('CREATE INDEX pallets_stored ON pallets (label) WHERE depot_id IS NOT NULL');

    expect(duplicates())->toBe([]);
});

/**
 * Two indexes over the same text column under different collations sort their
 * entries in genuinely different orders — bytewise under "C", by locale rules
 * under the database default — and PostgreSQL will only use each for the
 * collation it was built for. Everything else about them is identical: same
 * table, same column, same operator class, same options. Only indcollation
 * separates them, which is exactly why it has to be in the signature. Calling
 * one a copy of the other means telling somebody to drop an index that is the
 * only thing serving that ordering.
 */
it('does not call two indexes with different collations duplicates', function (): void {
    DB::statement('CREATE INDEX pallets_label_default ON pallets (label)');
    DB::statement('CREATE INDEX pallets_label_c ON pallets (label COLLATE "C")');

    expect(duplicates())->toBe([]);
});

it('does call two indexes with the same explicit collation duplicates', function (): void {
    // The other half of the same fact: matching collations are still duplicates,
    // so the fix narrows the signature rather than disabling the rule.
    DB::statement('CREATE INDEX pallets_label_c ON pallets (label COLLATE "C")');
    DB::statement('CREATE INDEX pallets_label_c_again ON pallets (label COLLATE "C")');

    expect(duplicates())->toHaveCount(1);
});

it('does call two partial indexes with the same predicate duplicates', function (): void {
    DB::statement('CREATE INDEX pallets_shipped ON pallets (label) WHERE depot_id IS NULL');
    DB::statement('CREATE INDEX pallets_shipped_again ON pallets (label) WHERE depot_id IS NULL');

    expect(duplicates())->toHaveCount(1);
});
