<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Lessons\UnindexedForeignKeys;
use Heyosseus\Vacuum\Queries\Constraints;
use Heyosseus\Vacuum\Queries\TableProfiles;
use Heyosseus\Vacuum\Values\Constraint;
use Heyosseus\Vacuum\Values\TableProfile;
use Illuminate\Support\Facades\DB;

/**
 * A parent table with two children: one foreign key backed by an index and one
 * that is not. Only the unindexed one is what this lesson exists to name, so the
 * real-data test proves it can tell the two apart on a live database rather than
 * merely on values built by hand.
 */
beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_fk_orders');
    DB::statement('DROP TABLE IF EXISTS learn_fk_invoices');
    DB::statement('DROP TABLE IF EXISTS learn_fk_customers');

    DB::statement('CREATE TABLE learn_fk_customers (id serial PRIMARY KEY)');
    DB::insert('INSERT INTO learn_fk_customers DEFAULT VALUES');

    // Indexed foreign key: the constraint plus a covering index on the same column.
    DB::statement(
        'CREATE TABLE learn_fk_invoices ('
        .'id serial PRIMARY KEY, '
        .'customer_id int REFERENCES learn_fk_customers (id)'
        .')'
    );
    DB::statement('CREATE INDEX learn_fk_invoices_customer_id_idx ON learn_fk_invoices (customer_id)');

    // Unindexed foreign key: the constraint and nothing else, the case this lesson exists for.
    DB::statement(
        'CREATE TABLE learn_fk_orders ('
        .'id serial PRIMARY KEY, '
        .'customer_id int REFERENCES learn_fk_customers (id)'
        .')'
    );

    flushStatistics();
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_fk_orders');
    DB::statement('DROP TABLE IF EXISTS learn_fk_invoices');
    DB::statement('DROP TABLE IF EXISTS learn_fk_customers');
});

it('names the unindexed foreign key and not the indexed one', function (): void {
    $lesson = new UnindexedForeignKeys(app(Constraints::class), app(TableProfiles::class));

    $observation = $lesson->observe();

    expect($observation->isEmpty())->toBeFalse()
        ->and($observation->columns)->toBe(['constraint', 'table', 'column(s)', 'references', 'rows in table']);

    $tables = implode(' ', array_map(static fn (array $row): string => $row[1], $observation->rows));

    expect($tables)->toContain('learn_fk_orders')
        ->and($tables)->not->toContain('learn_fk_invoices')
        ->and($observation->headline)->toContain('learn_fk_orders');
});

it('says so when nothing is missing an index', function (): void {
    DB::statement('DROP TABLE IF EXISTS learn_fk_orders');

    $lesson = new UnindexedForeignKeys(app(Constraints::class), app(TableProfiles::class));

    $observation = $lesson->observe();

    // learn_fk_invoices is the only foreign key left and it is indexed, but other
    // schemas on this shared connection may still hold their own unindexed ones,
    // so this only proves the sentence shape holds when there is nothing to name --
    // not that this specific database has none.
    if ($observation->isEmpty()) {
        expect($observation->headline)->toContain('None of the foreign keys')
            ->and($observation->note)->toContain('never indexes a foreign key');
    } else {
        $tables = implode(' ', array_map(static fn (array $row): string => $row[1], $observation->rows));

        expect($tables)->not->toContain('learn_fk_invoices');
    }
});

it('hands the reader a runnable statement for band three', function (): void {
    $lesson = new UnindexedForeignKeys(app(Constraints::class), app(TableProfiles::class));

    expect($lesson->tryIt())->toBeString()
        ->and($lesson->tryIt())->toContain('pg_constraint');
});

it('names its slug, title, tier, hook and entry-point position', function (): void {
    $lesson = new UnindexedForeignKeys(app(Constraints::class), app(TableProfiles::class));

    expect($lesson->slug())->toBe('unindexed-foreign-keys')
        ->and($lesson->title())->toBe('The index Eloquent does not create')
        ->and($lesson->tier())->toBe(Heyosseus\Vacuum\Learn\Tier::Eloquent)
        ->and($lesson->hook())->not->toBeEmpty()
        ->and($lesson->after())->toBeNull();
});

it('delegates tree() to fork() using its own live data', function (): void {
    $lesson = new UnindexedForeignKeys(app(Constraints::class), app(TableProfiles::class));

    $tree = $lesson->tree();

    expect($tree->question)->toBe('Which unindexed foreign keys actually cost you anything?')
        ->and($tree->branches)->toHaveCount(2);
});

/**
 * fork() is public precisely so a large table and a small table can be proven to
 * land on different branches without depending on the shared test database
 * happening to contain a 10,000-row table with an unindexed foreign key.
 */
it('sends an unindexed foreign key on a large table down the large branch', function (): void {
    $lesson = new UnindexedForeignKeys(app(Constraints::class), app(TableProfiles::class));

    $bigFk = new Constraint(
        schema: 'public',
        table: 'orders',
        name: 'orders_customer_id_fkey',
        kind: 'f',
        columns: ['customer_id'],
        referencedTable: 'customers',
        indexed: false,
    );

    $bigProfile = tableProfileWithRows('public', 'orders', 50_000);

    $tree = $lesson->fork([$bigFk], [$bigProfile]);

    [$large, $small] = $tree->branches;

    expect($large->isTaken())->toBeTrue()
        ->and($large->landed[0])->toContain('public.orders')
        ->and($large->fix)->not->toBeNull()
        ->and($large->fix)->toContain('create index concurrently')
        ->and($large->fix)->toContain('public.orders')
        ->and($large->fix)->toContain('customer_id')
        ->and($small->isTaken())->toBeFalse();
});

it('sends an unindexed foreign key on a small table down the small branch, with no fix', function (): void {
    $lesson = new UnindexedForeignKeys(app(Constraints::class), app(TableProfiles::class));

    $smallFk = new Constraint(
        schema: 'public',
        table: 'tags',
        name: 'tags_owner_id_fkey',
        kind: 'f',
        columns: ['owner_id'],
        referencedTable: 'owners',
        indexed: false,
    );

    $smallProfile = tableProfileWithRows('public', 'tags', 12);

    $tree = $lesson->fork([$smallFk], [$smallProfile]);

    [$large, $small] = $tree->branches;

    expect($large->isTaken())->toBeFalse()
        ->and($small->isTaken())->toBeTrue()
        ->and($small->landed[0])->toContain('public.tags')
        ->and($small->fix)->toBeNull();
});

it('leaves an indexed foreign key off the tree entirely', function (): void {
    $lesson = new UnindexedForeignKeys(app(Constraints::class), app(TableProfiles::class));

    $indexedFk = new Constraint(
        schema: 'public',
        table: 'orders',
        name: 'orders_customer_id_fkey',
        kind: 'f',
        columns: ['customer_id'],
        referencedTable: 'customers',
        indexed: true,
    );

    $profile = tableProfileWithRows('public', 'orders', 50_000);

    $tree = $lesson->fork([$indexedFk], [$profile]);

    [$large, $small] = $tree->branches;

    expect($large->isTaken())->toBeFalse()
        ->and($small->isTaken())->toBeFalse();
});

it('ignores primary key and unique constraints, which are never the point of this lesson', function (): void {
    $lesson = new UnindexedForeignKeys(app(Constraints::class), app(TableProfiles::class));

    $primaryKey = new Constraint(
        schema: 'public',
        table: 'orders',
        name: 'orders_pkey',
        kind: 'p',
        columns: ['id'],
        referencedTable: '',
        indexed: true,
    );

    $profile = tableProfileWithRows('public', 'orders', 50_000);

    $tree = $lesson->fork([$primaryKey], [$profile]);

    [$large, $small] = $tree->branches;

    expect($large->isTaken())->toBeFalse()
        ->and($small->isTaken())->toBeFalse();
});

/**
 * Builds a TableProfile with every field a lesson could plausibly read, since the
 * constructor takes no defaults for most of them. Only qualifiedName() and
 * liveTuples are exercised by this lesson, but the value object cannot be built
 * partially.
 */
function tableProfileWithRows(string $schema, string $name, int $liveTuples): TableProfile
{
    return new TableProfile(
        schema: $schema,
        name: $name,
        liveTuples: $liveTuples,
        deadTuples: 0,
        modificationsSinceAnalyze: 0,
        xidAge: 0,
        mxidAge: 0,
        heapBytes: 0,
        indexBytes: 0,
        toastBytes: 0,
        totalBytes: 0,
        sequentialScans: 0,
        sequentialTuplesRead: 0,
        indexScans: 0,
        indexTuplesFetched: 0,
        inserts: 0,
        updates: 0,
        hotUpdates: 0,
        deletes: 0,
        lastVacuum: null,
        lastAutovacuum: null,
        lastAnalyze: null,
        lastAutoanalyze: null,
        vacuumScaleFactor: 0.2,
        vacuumThreshold: 50,
        analyzeScaleFactor: 0.1,
        analyzeThreshold: 50,
        tuned: false,
    );
}
