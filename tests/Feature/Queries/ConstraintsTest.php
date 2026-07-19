<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\Constraints;
use Heyosseus\Vacuum\Values\Constraint;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('drop table if exists learn_children, learn_parents cascade');
    DB::statement('create table learn_parents (id bigserial primary key)');
    DB::statement('create table learn_children (
        id bigserial primary key,
        bare_id bigint references learn_parents (id),
        covered_id bigint references learn_parents (id),
        trailing_id bigint references learn_parents (id),
        status text
    )');
    DB::statement('create index on learn_children (covered_id, status)');
    DB::statement('create index on learn_children (status, trailing_id)');
});

afterEach(function (): void {
    DB::statement('drop table if exists learn_children, learn_parents cascade');
});

function constraintOn(string $column): Constraint
{
    $found = array_values(array_filter(
        app(Constraints::class)->all(),
        static fn (Constraint $c): bool => $c->table === 'learn_children' && $c->columns === [$column],
    ));

    expect($found)->toHaveCount(1);

    return $found[0];
}

it('reports a foreign key with no index at all as uncovered', function (): void {
    expect(constraintOn('bare_id')->indexed)->toBeFalse();
});

it('reports a foreign key that leads an index as covered', function (): void {
    expect(constraintOn('covered_id')->indexed)->toBeTrue();
});

it('refuses to call a trailing index column covered', function (): void {
    // (status, trailing_id) cannot serve a lookup on trailing_id alone. Calling
    // this covered is the failure mode that would invert the whole lesson.
    expect(constraintOn('trailing_id')->indexed)->toBeFalse();
});

it('reports the primary key as covered, because PostgreSQL indexes it for you', function (): void {
    $primary = array_values(array_filter(
        app(Constraints::class)->all(),
        static fn (Constraint $c): bool => $c->table === 'learn_children' && $c->kind === 'p',
    ));

    expect($primary[0]->indexed)->toBeTrue();
});

it('names the table a foreign key points at', function (): void {
    expect(constraintOn('bare_id')->referencedTable)->toBe('learn_parents')
        ->and(constraintOn('bare_id')->isForeignKey())->toBeTrue();
});
