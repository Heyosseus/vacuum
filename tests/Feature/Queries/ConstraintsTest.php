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

it('carries the types on both sides of a foreign key', function (): void {
    // A foreign key from an integer to a bigint has an index that the planner
    // cannot use, and nothing in the catalog complains about it.
    DB::statement('DROP TABLE IF EXISTS lint_children CASCADE');
    DB::statement('DROP TABLE IF EXISTS lint_parents CASCADE');
    DB::statement('CREATE TABLE lint_parents (id bigserial PRIMARY KEY)');
    DB::statement('CREATE TABLE lint_children (id bigserial PRIMARY KEY, parent_id integer REFERENCES lint_parents (id))');

    $foreignKey = null;

    foreach (app(Constraints::class)->all() as $constraint) {
        if ($constraint->table === 'lint_children' && $constraint->isForeignKey()) {
            $foreignKey = $constraint;
        }
    }

    expect($foreignKey?->columnTypes)->toBe(['integer'])
        ->and($foreignKey?->referencedColumnTypes)->toBe(['bigint'])
        ->and($foreignKey?->typesMatch())->toBeFalse();

    DB::statement('DROP TABLE IF EXISTS lint_children CASCADE');
    DB::statement('DROP TABLE IF EXISTS lint_parents CASCADE');
});

it('keeps a numeric(10,2) type as one element instead of splitting on its own comma', function (): void {
    // format_type renders numeric(10,2) with a comma already inside it. This
    // package's own migration uses $table->decimal('value', 20), so a
    // comma-joined list splitting one type into two is not an exotic failure.
    DB::statement('DROP TABLE IF EXISTS numeric_children CASCADE');
    DB::statement('DROP TABLE IF EXISTS numeric_parents CASCADE');
    DB::statement('CREATE TABLE numeric_parents (id numeric(10,2) PRIMARY KEY)');
    DB::statement('CREATE TABLE numeric_children (id bigserial PRIMARY KEY, parent_id numeric(10,2) REFERENCES numeric_parents (id))');

    $foreignKey = null;

    foreach (app(Constraints::class)->all() as $constraint) {
        if ($constraint->table === 'numeric_children' && $constraint->isForeignKey()) {
            $foreignKey = $constraint;
        }
    }

    expect($foreignKey?->columnTypes)->toBe(['numeric(10,2)'])
        ->and($foreignKey?->referencedColumnTypes)->toBe(['numeric(10,2)'])
        ->and($foreignKey?->typesMatch())->toBeTrue();

    DB::statement('DROP TABLE IF EXISTS numeric_children CASCADE');
    DB::statement('DROP TABLE IF EXISTS numeric_parents CASCADE');
});

it('does not count a foreign key covered only by a partial index as indexed', function (): void {
    // A partial index holds only the rows its predicate admits. The
    // referential-integrity check this column answers for needs to see an
    // arbitrary row in the parent, not just the ones a WHERE clause let in, so
    // a partial index must not count as coverage here -- the same rule
    // Values\TableSchema::hasIndexLeadingWith() already applies.
    DB::statement('DROP TABLE IF EXISTS partial_children CASCADE');
    DB::statement('DROP TABLE IF EXISTS partial_parents CASCADE');
    DB::statement('CREATE TABLE partial_parents (id bigserial PRIMARY KEY)');
    DB::statement('CREATE TABLE partial_children (id bigserial PRIMARY KEY, parent_id bigint REFERENCES partial_parents (id), active boolean)');
    DB::statement('CREATE INDEX ON partial_children (parent_id) WHERE active');

    $foreignKey = null;

    foreach (app(Constraints::class)->all() as $constraint) {
        if ($constraint->table === 'partial_children' && $constraint->isForeignKey()) {
            $foreignKey = $constraint;
        }
    }

    expect($foreignKey?->indexed)->toBeFalse();

    DB::statement('DROP TABLE IF EXISTS partial_children CASCADE');
    DB::statement('DROP TABLE IF EXISTS partial_parents CASCADE');
});
