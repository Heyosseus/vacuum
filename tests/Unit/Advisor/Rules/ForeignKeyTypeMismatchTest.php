<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\ForeignKeyTypeMismatch;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Constraint;
use Heyosseus\Vacuum\Values\TableSchema;

function typedKey(array $here, array $there, string $column = 'customer_id'): TableSchema
{
    return new TableSchema('public', 'orders', [], [
        new Constraint(
            schema: 'public',
            table: 'orders',
            name: 'orders_'.$column.'_foreign',
            kind: 'f',
            columns: [$column],
            referencedTable: 'customers',
            indexed: true,
            columnTypes: $here,
            referencedColumnTypes: $there,
        ),
    ], []);
}

it('reports a key whose type differs from the one it references', function (): void {
    $findings = app(ForeignKeyTypeMismatch::class)->inspect(typedKey(['integer'], ['bigint']));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('foreign-key-type-mismatch')
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->subject)->toBe('public.orders.customer_id')
        ->and($findings[0]->evidence)->toBe('integer references bigint');
});

it('says nothing when both sides agree', function (): void {
    expect(app(ForeignKeyTypeMismatch::class)->inspect(typedKey(['bigint'], ['bigint'])))->toBe([]);
});

it('says nothing when the types were not looked up', function (): void {
    // An empty list on either side means the query did not answer, not that the
    // types differ. Reporting a mismatch from missing evidence is the way a tool
    // teaches people to ignore it.
    expect(app(ForeignKeyTypeMismatch::class)->inspect(typedKey([], [])))->toBe([])
        ->and(app(ForeignKeyTypeMismatch::class)->inspect(typedKey(['bigint'], [])))->toBe([]);
});

it('explains that the index exists and cannot be used', function (): void {
    $findings = app(ForeignKeyTypeMismatch::class)->inspect(typedKey(['integer'], ['bigint']));

    expect($findings[0]->impact)->toContain('cannot')
        ->and($findings[0]->impact)->toContain('customers');
});

it('offers the type change, and warns that it rewrites the table', function (): void {
    $findings = app(ForeignKeyTypeMismatch::class)->inspect(typedKey(['integer'], ['bigint']));

    expect($findings[0]->remediation)
        ->toContain('ALTER TABLE "public"."orders"')
        ->and($findings[0]->remediation)->toContain('TYPE bigint')
        ->and($findings[0]->impact)->toContain('rewrite');
});

it('refuses to advise narrowing when the parent is the narrow side', function (): void {
    // A legacy increments('id') parent referenced by a newer foreignId() child
    // is the most common real shape of this defect: the parent is integer, the
    // child is bigint. Narrowing the child to match would entrench the very
    // ceiling int4-primary-key already warns about on the parent, and it would
    // fail outright once a value in the child exceeds what integer can hold.
    $findings = app(ForeignKeyTypeMismatch::class)->inspect(typedKey(['bigint'], ['integer']));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->remediation)->toBeNull()
        ->and($findings[0]->impact)->toContain('customers')
        ->and($findings[0]->impact)->toContain('narrow')
        ->and($findings[0]->impact)->toContain('widen');
});

it('still offers the widening ALTER when the parent is the wider side', function (): void {
    // The opposite direction is the existing, correct behaviour: the child is
    // the narrow side, so widening it to match the parent is a real fix.
    $findings = app(ForeignKeyTypeMismatch::class)->inspect(typedKey(['integer'], ['bigint']));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->remediation)
        ->toBe('ALTER TABLE "public"."orders" ALTER COLUMN "customer_id" TYPE bigint;');
});

it('offers the widening ALTER when the mismatch is outside the integer family entirely', function (): void {
    // uuid vs bigint has no narrow/wide relationship this rule knows how to
    // reason about, so the narrow-parent guard must decline to apply and the
    // existing widen-the-child advice has to stand.
    $findings = app(ForeignKeyTypeMismatch::class)->inspect(typedKey(['uuid'], ['bigint']));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->remediation)->not->toBeNull();
});

it('offers the widening ALTER when only the child side is in the integer family', function (): void {
    // The child (integer) is rankable but the parent (uuid) is not, so there is
    // still no narrow/wide relationship to reason about and the guard must not
    // fire from the child side alone.
    $findings = app(ForeignKeyTypeMismatch::class)->inspect(typedKey(['integer'], ['uuid']));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->remediation)->not->toBeNull();
});

it('skips a composite column pair whose own type was not looked up', function (): void {
    // Not reachable from the catalog, where columnTypes is always as long as
    // columns for a composite key -- but the loop indexes into both lists
    // positionally and must not do so blindly.
    $table = new TableSchema('public', 'orders', [], [
        new Constraint(
            schema: 'public',
            table: 'orders',
            name: 'orders_composite_short_here',
            kind: 'f',
            columns: ['tenant_id', 'customer_id'],
            referencedTable: 'customers',
            indexed: true,
            columnTypes: ['bigint'],
            referencedColumnTypes: ['bigint', 'bigint'],
        ),
    ], []);

    $findings = app(ForeignKeyTypeMismatch::class)->inspect($table);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->remediation)->toBeNull()
        ->and($findings[0]->impact)->not->toContain('customer_id (');
});

it('skips a composite column pair whose parent type was not looked up', function (): void {
    // Not reachable from the catalog, where referencedColumnTypes is always as
    // long as columns for a composite key -- but the loop indexes into both
    // lists positionally and must not do so blindly.
    $table = new TableSchema('public', 'orders', [], [
        new Constraint(
            schema: 'public',
            table: 'orders',
            name: 'orders_composite_short_there',
            kind: 'f',
            columns: ['tenant_id', 'customer_id'],
            referencedTable: 'customers',
            indexed: true,
            columnTypes: ['bigint', 'integer'],
            referencedColumnTypes: ['bigint'],
        ),
    ], []);

    $findings = app(ForeignKeyTypeMismatch::class)->inspect($table);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->remediation)->toBeNull()
        ->and($findings[0]->impact)->not->toContain('customer_id (');
});

it('says nothing about a foreign key with no columns', function (): void {
    // Not reachable from the catalog, but the rule indexes into the column list
    // to name its subject and must not do so blindly.
    $table = new TableSchema('public', 'orders', [], [
        new Constraint(
            schema: 'public',
            table: 'orders',
            name: 'orders_empty',
            kind: 'f',
            columns: [],
            referencedTable: 'customers',
            indexed: true,
            columnTypes: ['integer'],
            referencedColumnTypes: ['bigint'],
        ),
    ], []);

    expect(app(ForeignKeyTypeMismatch::class)->inspect($table))->toBe([]);
});

it('refuses to advise an ALTER on a composite key, naming the columns that disagree', function (): void {
    // Column 0 (tenant_id) already matches; only column 1 (customer_id) does
    // not. The bug this guards against rewrote column 0 unconditionally --
    // taking the table's lock and rebuilding an index to fix a column that was
    // never wrong, while leaving the real mismatch untouched.
    $table = new TableSchema('public', 'orders', [], [
        new Constraint(
            schema: 'public',
            table: 'orders',
            name: 'orders_composite_foreign',
            kind: 'f',
            columns: ['tenant_id', 'customer_id'],
            referencedTable: 'customers',
            indexed: true,
            columnTypes: ['bigint', 'integer'],
            referencedColumnTypes: ['bigint', 'bigint'],
        ),
    ], []);

    $findings = app(ForeignKeyTypeMismatch::class)->inspect($table);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('foreign-key-type-mismatch')
        ->and($findings[0]->subject)->toBe('public.orders.tenant_id')
        ->and($findings[0]->remediation)->toBeNull()
        ->and($findings[0]->impact)->toContain('composite')
        ->and($findings[0]->impact)->toContain('customer_id (integer vs bigint)')
        ->and($findings[0]->impact)->not->toContain('tenant_id (');
});
