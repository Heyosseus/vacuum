<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\MissingPrimaryKey;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Column;
use Heyosseus\Vacuum\Values\Constraint;
use Heyosseus\Vacuum\Values\TableSchema;

function pivotColumn(string $name, bool $nullable = false): Column
{
    return new Column(schema: 'public', table: 'role_user', name: $name, type: 'bigint', nullable: $nullable);
}

function unkeyed(Constraint ...$constraints): TableSchema
{
    // The columns matter: promoting a unique constraint to a primary key requires
    // every one of its columns to be NOT NULL, so the rule checks them.
    return new TableSchema(
        'public',
        'role_user',
        [pivotColumn('role_id'), pivotColumn('user_id')],
        array_values($constraints),
        [],
    );
}

function unique(array $columns): Constraint
{
    return new Constraint(
        schema: 'public', table: 'role_user', name: 'role_user_unique',
        kind: 'u', columns: $columns, referencedTable: '', indexed: true,
    );
}

it('reports a table with no primary key', function (): void {
    $findings = app(MissingPrimaryKey::class)->inspect(unkeyed());

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('missing-primary-key')
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->subject)->toBe('public.role_user')
        ->and($findings[0]->table)->toBe('public.role_user');
});

it('says nothing about a table that has one', function (): void {
    $primary = new Constraint(
        schema: 'public', table: 'role_user', name: 'role_user_pkey',
        kind: 'p', columns: ['id'], referencedTable: '', indexed: true,
    );

    expect(app(MissingPrimaryKey::class)->inspect(unkeyed($primary)))->toBe([]);
});

it('suggests promoting an existing unique constraint rather than inventing a column', function (): void {
    // A pivot table with a unique pair already has a key; it just has not been
    // told that it is one. Suggesting a new surrogate id would be worse advice.
    $findings = app(MissingPrimaryKey::class)->inspect(unkeyed(unique(['role_id', 'user_id'])));

    expect($findings[0]->remediation)
        ->toBe('ALTER TABLE "public"."role_user" ADD PRIMARY KEY ("role_id", "user_id");')
        ->and($findings[0]->summary)->toContain('role_user_unique');
});

it('offers no statement when there is no unique constraint to promote', function (): void {
    // Which column should be the key is a question about the domain, and a guess
    // dressed as a migration is worse than saying nothing.
    expect(app(MissingPrimaryKey::class)->inspect(unkeyed())[0]->remediation)->toBeNull();
});

it('explains replication and Eloquent, not just tidiness', function (): void {
    $findings = app(MissingPrimaryKey::class)->inspect(unkeyed());

    expect($findings[0]->impact)->toContain('replica')
        ->and($findings[0]->impact)->toContain('Eloquent');
});

it('does not offer to promote a unique constraint over a nullable column', function (): void {
    // PostgreSQL treats nulls as distinct, so such a constraint permits rows a
    // primary key would refuse and the promotion would simply fail.
    $table = new TableSchema(
        'public',
        'role_user',
        [pivotColumn('role_id', nullable: true)],
        [unique(['role_id'])],
        [],
    );

    expect(app(MissingPrimaryKey::class)->inspect($table)[0]->remediation)->toBeNull();
});

it('ignores a unique constraint with no columns', function (): void {
    // Not reachable from the catalog, but the rule indexes into the column list
    // to check nullability and must not do so blindly.
    expect(app(MissingPrimaryKey::class)->inspect(unkeyed(unique([])))[0]->remediation)->toBeNull();
});

it('ignores a unique constraint over a column the table does not have', function (): void {
    // Defensive, for the same reason: promoting a constraint means reading each
    // of its columns' nullability, and a name the catalog did not also hand
    // back as a column is not one the rule can vouch for.
    expect(app(MissingPrimaryKey::class)->inspect(unkeyed(unique(['ghost_column'])))[0]->remediation)->toBeNull();
});

it('does not consider a foreign key promotable', function (): void {
    // A table with no primary key can still have other constraints. Only a
    // unique constraint is a candidate; every other kind is skipped outright,
    // without ever looking at its columns.
    $foreign = new Constraint(
        schema: 'public', table: 'role_user', name: 'role_user_role_id_foreign',
        kind: 'f', columns: ['role_id'], referencedTable: 'roles', indexed: true,
    );

    expect(app(MissingPrimaryKey::class)->inspect(unkeyed($foreign))[0]->remediation)->toBeNull();
});
