<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\Int4PrimaryKey;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Column;
use Heyosseus\Vacuum\Values\Constraint;
use Heyosseus\Vacuum\Values\TableSchema;

function keyed(string $type, array $keyColumns = ['id']): TableSchema
{
    $columns = [];

    foreach ($keyColumns as $name) {
        $columns[] = new Column(schema: 'public', table: 'orders', name: $name, type: $type, nullable: false);
    }

    return new TableSchema('public', 'orders', $columns, [
        new Constraint(
            schema: 'public', table: 'orders', name: 'orders_pkey',
            kind: 'p', columns: $keyColumns, referencedTable: '', indexed: true,
        ),
    ], []);
}

it('reports a primary key on integer', function (): void {
    $findings = app(Int4PrimaryKey::class)->inspect(keyed('integer'));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('int4-primary-key')
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->subject)->toBe('public.orders.id')
        ->and($findings[0]->summary)->toContain('2,147,483,647');
});

it('reports a primary key on smallint', function (): void {
    expect(app(Int4PrimaryKey::class)->inspect(keyed('smallint')))->toHaveCount(1);
});

it('says nothing about a bigint key', function (): void {
    expect(app(Int4PrimaryKey::class)->inspect(keyed('bigint')))->toBe([]);
});

it('says nothing about a uuid or text key', function (): void {
    expect(app(Int4PrimaryKey::class)->inspect(keyed('uuid')))->toBe([])
        ->and(app(Int4PrimaryKey::class)->inspect(keyed('text')))->toBe([]);
});

it('says nothing about a table with no primary key', function (): void {
    // That is missing-primary-key's finding to make, not this one's. Two rules
    // firing on one defect is how a report becomes noise.
    expect(app(Int4PrimaryKey::class)->inspect(new TableSchema('public', 'orders', [], [], [])))->toBe([]);
});

it('reports each narrow column of a composite key', function (): void {
    expect(app(Int4PrimaryKey::class)->inspect(keyed('integer', ['tenant_id', 'order_id'])))->toHaveCount(2);
});

it('says nothing when the key column is not in the column list', function (): void {
    $orphan = new TableSchema('public', 'orders', [], [
        new Constraint(
            schema: 'public', table: 'orders', name: 'orders_pkey',
            kind: 'p', columns: ['id'], referencedTable: '', indexed: true,
        ),
    ], []);

    expect(app(Int4PrimaryKey::class)->inspect($orphan))->toBe([]);
});

it('says nothing about Laravel\'s own migrations table', function (): void {
    // DatabaseMigrationRepository still creates this table with
    // $table->increments('id') in every supported Laravel release, so without
    // this exemption every stock `laravel new` app would fail vacuum:lint's
    // default --fail-on=warning on a framework table it cannot change and
    // that gains one row per migration run.
    $migrations = new TableSchema('public', 'migrations', [
        new Column(schema: 'public', table: 'migrations', name: 'id', type: 'integer', nullable: false),
    ], [
        new Constraint(
            schema: 'public', table: 'migrations', name: 'migrations_pkey',
            kind: 'p', columns: ['id'], referencedTable: '', indexed: true,
        ),
    ], []);

    expect(app(Int4PrimaryKey::class)->inspect($migrations))->toBe([]);
});

it('offers the widening, and says it rewrites the table', function (): void {
    $findings = app(Int4PrimaryKey::class)->inspect(keyed('integer'));

    expect($findings[0]->remediation)
        ->toBe('ALTER TABLE "public"."orders" ALTER COLUMN "id" TYPE bigint;')
        ->and($findings[0]->impact)->toContain('rewrite');
});
