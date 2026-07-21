<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Internals\Support\RelationCatalog;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS vacuum_catalog_demo CASCADE');
    DB::statement('CREATE TABLE vacuum_catalog_demo (id serial PRIMARY KEY, label text)');
    DB::statement('CREATE OR REPLACE VIEW vacuum_catalog_view AS SELECT id FROM vacuum_catalog_demo');
});

afterEach(function (): void {
    DB::statement('DROP VIEW IF EXISTS vacuum_catalog_view');
    DB::statement('DROP TABLE IF EXISTS vacuum_catalog_demo CASCADE');
});

function catalog(): RelationCatalog
{
    return app(RelationCatalog::class);
}

it('resolves an ordinary table', function (): void {
    expect(catalog()->resolve('public', 'vacuum_catalog_demo'))
        ->toBe('"public"."vacuum_catalog_demo"');
});

it('refuses a relation that does not exist', function (): void {
    catalog()->resolve('public', 'no_such_table_anywhere');
})->throws(InvalidArgumentException::class, 'No such relation');

it('refuses a view rather than letting it reach a page function', function (): void {
    // Existing in pg_class was the whole test before, and pg_class holds views,
    // indexes, sequences and composite types alongside tables. A view has no
    // ctid, so this used to leave the reader with a 500 and a stack trace.
    catalog()->resolve('public', 'vacuum_catalog_view');
})->throws(InvalidArgumentException::class, 'a view');

it('refuses an index, which would otherwise decode into confident nonsense', function (): void {
    // The worst of the set, because nothing errors. heap_page_items happily reads
    // a B-tree page and returns index tuples decoded as heap tuples: a full line
    // pointer panel, authoritative-looking, meaning nothing at all.
    catalog()->resolve('public', 'vacuum_catalog_demo_pkey');
})->throws(InvalidArgumentException::class, 'an index');

it('explains that a partitioned table has no storage of its own', function (): void {
    // Not an error so much as a redirection: the rows are real, they are just in
    // the partitions, and that is the useful thing to say.
    DB::statement('DROP TABLE IF EXISTS vacuum_catalog_parted CASCADE');
    DB::statement('CREATE TABLE vacuum_catalog_parted (id int, region text) PARTITION BY LIST (region)');

    try {
        expect(fn (): string => catalog()->resolve('public', 'vacuum_catalog_parted'))
            ->toThrow(InvalidArgumentException::class, 'no storage of its own');
    } finally {
        DB::statement('DROP TABLE IF EXISTS vacuum_catalog_parted CASCADE');
    }
});

it('resolves a materialized view, which does store heap pages', function (): void {
    DB::statement('DROP MATERIALIZED VIEW IF EXISTS vacuum_catalog_matview');
    DB::statement('CREATE MATERIALIZED VIEW vacuum_catalog_matview AS SELECT id FROM vacuum_catalog_demo');

    try {
        expect(catalog()->resolve('public', 'vacuum_catalog_matview'))
            ->toBe('"public"."vacuum_catalog_matview"');
    } finally {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS vacuum_catalog_matview');
    }
});

it('refuses a sequence', function (): void {
    catalog()->resolve('public', 'vacuum_catalog_demo_id_seq');
})->throws(InvalidArgumentException::class, 'a sequence');
