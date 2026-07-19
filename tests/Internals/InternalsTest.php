<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Internals\Explorers\HeapPages;
use Heyosseus\Vacuum\Vacuum;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);

    DB::statement('DROP TABLE IF EXISTS vacuum_page_demo');
    DB::statement('CREATE TABLE vacuum_page_demo (id serial PRIMARY KEY, label text)');
    DB::insert("INSERT INTO vacuum_page_demo (label) SELECT 'x' || i FROM generate_series(1, 50) i");

    try {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pageinspect');
    } catch (Throwable) {
        // Left absent. Every test below that needs it is gated on availability()
        // and reports why it skipped, rather than failing over an extension this
        // environment cannot install.
    }
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS vacuum_page_demo');
    DB::statement('DROP TABLE IF EXISTS vacuum_hot_demo');
});

// Checked inline, rather than through a shared helper, because
// tests\Feature\Internals\HeapPagesTest already declares a global
// pageInspectAvailable() -- a second declaration here would fatal the moment
// both files load into the same suite.
const PAGEINSPECT_UNAVAILABLE = 'pageinspect is unavailable here (missing extension or insufficient privilege).';

it('offers a relation to look inside when none is chosen', function (): void {
    $this->get('/vacuum/internals')
        ->assertOk()
        ->assertSee('Choose a table', false)
        ->assertSee('public.vacuum_page_demo', false);
});

it('renders a real page when a relation and block are chosen', function (): void {
    $this->get('/vacuum/internals?schema=public&table=vacuum_page_demo&block=0')
        ->assertOk()
        ->assertSee('Line pointers', false);
})->skip(fn (): bool => ! app(HeapPages::class)->availability()->available, PAGEINSPECT_UNAVAILABLE);

it('says so in the panel rather than failing when the block does not exist', function (): void {
    $this->get('/vacuum/internals?schema=public&table=vacuum_page_demo&block=99999')
        ->assertOk()
        ->assertSee('no block', false);
})->skip(fn (): bool => ! app(HeapPages::class)->availability()->available, PAGEINSPECT_UNAVAILABLE);

it('says so in the panel rather than failing when the table itself does not exist', function (): void {
    // No block number touches this path: blockCount() and RowVersions::explore()
    // both resolve the relation before either reads anything, and a URL naming a
    // table that was dropped (or never existed) must fail the same way an
    // out-of-range block does -- a panel, not a 500.
    $this->get('/vacuum/internals?schema=public&table=vacuum_no_such_demo&block=0')
        ->assertOk()
        ->assertSee('no block', false);
});

it('draws a HOT chain as a chain', function (): void {
    DB::statement('DROP TABLE IF EXISTS vacuum_hot_demo');
    DB::statement('CREATE TABLE vacuum_hot_demo (id serial PRIMARY KEY, label text) WITH (fillfactor = 50)');
    DB::insert("INSERT INTO vacuum_hot_demo (label) SELECT 'x' || i FROM generate_series(1, 50) i");
    DB::update("UPDATE vacuum_hot_demo SET label = label || '!'");

    // The update alone leaves every old tuple LP_NORMAL with its t_ctid pointing
    // at the replacement -- pruning is what turns an index-referenced root into
    // the LP_REDIRECT a chain is read from, and a plain VACUUM triggers that.
    DB::statement('VACUUM vacuum_hot_demo');

    $block = app(HeapPages::class)->findInteresting('public', 'vacuum_hot_demo', 'hot');

    $this->get("/vacuum/internals?schema=public&table=vacuum_hot_demo&block={$block}")
        ->assertOk()
        ->assertSee('→', false);
})->skip(fn (): bool => ! app(HeapPages::class)->availability()->available, PAGEINSPECT_UNAVAILABLE);

it('explains itself instead of rendering an empty table when pageinspect is unavailable, and still shows row versions', function (): void {
    DB::statement('DROP EXTENSION IF EXISTS pageinspect');

    // Capabilities is bound as a singleton so a request only probes the server
    // once; forgetting it is what makes this instance re-read the catalog now
    // that the extension is gone.
    app()->forgetInstance(Capabilities::class);

    try {
        $this->get('/vacuum/internals?schema=public&table=vacuum_page_demo&block=0')
            ->assertOk()
            ->assertSee('not installed', false)
            ->assertSee('CREATE EXTENSION pageinspect', false)
            ->assertSee('Row versions', false)
            ->assertDontSee('Line pointers', false);
    } finally {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pageinspect');
        } catch (Throwable) {
            // Left absent, matching the state beforeEach already tolerates.
        }
    }
});
