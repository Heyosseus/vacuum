<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Vacuum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The explorer's failure modes, seen through the page rather than the class.
 *
 * The controller's docblock promised it turned the explorers' exceptions into a
 * panel instead of a 500, and it only ever did that for InvalidArgumentException.
 * A relation that was not a table produced one kind of 500 and a relation
 * PostgreSQL refused to read produced another, and the second is not a mistake
 * anybody made: pageinspect is superuser-restricted, relations get dropped
 * mid-request, and a matview can exist without ever having been populated.
 */
beforeEach(function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);

    DB::statement('DROP TABLE IF EXISTS vacuum_panel_demo CASCADE');
    DB::statement('CREATE TABLE vacuum_panel_demo (id serial PRIMARY KEY, label text)');
    DB::statement('CREATE OR REPLACE VIEW vacuum_panel_view AS SELECT id FROM vacuum_panel_demo');
});

afterEach(function (): void {
    DB::statement('DROP VIEW IF EXISTS vacuum_panel_view');
    DB::statement('DROP MATERIALIZED VIEW IF EXISTS vacuum_panel_empty');
    DB::statement('DROP TABLE IF EXISTS vacuum_panel_demo CASCADE');
});

it('explains a view in the panel instead of failing the request', function (): void {
    // SELECT ctid FROM a view is SQLSTATE 42703, and it used to arrive as a stack
    // trace.
    $this->get('/vacuum/internals?schema=public&table=vacuum_panel_view')
        ->assertOk()
        ->assertSee('a view');
});

it('explains an index rather than decoding its pages as rows', function (): void {
    // The quiet one. heap_page_items does not refuse a B-tree page -- it reads
    // index tuples as heap tuples and renders a full, authoritative-looking panel
    // of nonsense, which for a tool whose purpose is teaching is worse than any
    // error.
    $this->get('/vacuum/internals?schema=public&table=vacuum_panel_demo_pkey')
        ->assertOk()
        ->assertSee('an index');
});

it('renders the database\'s own refusal rather than a stack trace', function (): void {
    // A materialized view that exists and has never been populated: the catalog
    // resolves it, and PostgreSQL then refuses to read a row out of it.
    DB::statement('CREATE MATERIALIZED VIEW vacuum_panel_empty AS '
        .'SELECT id FROM vacuum_panel_demo WITH NO DATA');

    $this->get('/vacuum/internals?schema=public&table=vacuum_panel_empty')
        ->assertOk()
        ->assertSee('has not been populated', false);
});
