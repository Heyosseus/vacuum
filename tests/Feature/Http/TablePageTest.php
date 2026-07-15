<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Vacuum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);

    DB::statement('DROP TABLE IF EXISTS crates');
    DB::statement('CREATE TABLE crates (id serial PRIMARY KEY, label text)');
    DB::statement('CREATE INDEX crates_label_index ON crates (label)');
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 300) i");
    DB::statement('SELECT pg_stat_force_next_flush()');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
});

it('shows everything it knows about one table', function (): void {
    $this->get('/vacuum/tables/public/crates')
        ->assertOk()
        ->assertSee('public.crates')
        ->assertSee('crates_label_index')
        ->assertSee('Autovacuum')
        ->assertSee('HOT updates')
        ->assertSee('TOAST');
});

it('says how many dead rows autovacuum is waiting for', function (): void {
    // 50 plus a fifth of 300. The number nobody can work out from the setting, which
    // is the reason the page exists.
    $this->get('/vacuum/tables/public/crates')
        ->assertOk()
        ->assertSee('110');
});

it('does not have a page for a table that is not there', function (): void {
    $this->get('/vacuum/tables/public/no_such_table')->assertNotFound();
});

it('keeps a stranger out of a table page as firmly as out of the dashboard', function (): void {
    Vacuum::auth(static fn (Request $request): bool => false);

    $this->get('/vacuum/tables/public/crates')->assertForbidden();
});

it('does not let a table name from a url reach the statement', function (): void {
    // The name is bound, never spelled into the SQL. If it were interpolated this
    // would end the string and drop the table, and the assertion afterwards is that
    // the table is still there.
    $this->get("/vacuum/tables/public/crates'; DROP TABLE crates; --")->assertNotFound();

    expect(DB::scalar("SELECT count(*) FROM information_schema.tables WHERE table_name = 'crates'"))->toBe(1);
});
