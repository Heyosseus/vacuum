<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Internals\Explorers\HeapPages;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS vacuum_page_demo');
    DB::statement('CREATE TABLE vacuum_page_demo (id serial PRIMARY KEY, label text)');
    DB::insert("INSERT INTO vacuum_page_demo (label) SELECT 'x' || i FROM generate_series(1, 50) i");
    config()->set('vacuum.internals.enabled', true);

    try {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pageinspect');
    } catch (Throwable) {
        // Left absent. Every test below is gated on availability() and
        // reports why it skipped, rather than failing over an extension
        // this environment cannot install.
    }
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS vacuum_page_demo');
    DB::statement('DROP TABLE IF EXISTS vacuum_dead_demo');
    DB::statement('DROP TABLE IF EXISTS vacuum_hot_demo');
});

function pageInspectAvailable(): bool
{
    return app(HeapPages::class)->availability()->available;
}

const PAGEINSPECT_SKIP_REASON = 'pageinspect is unavailable here (missing extension or insufficient privilege).';

it('opens a real page and reads its header', function (): void {
    $page = app(HeapPages::class)->explore('public', 'vacuum_page_demo', 0);

    expect($page->block)->toBe(0)
        ->and($page->pageSize)->toBe(8192)
        ->and($page->freeBytes())->toBeGreaterThan(0)
        ->and($page->pointers)->not->toBeEmpty();
})->skip(fn (): bool => ! pageInspectAvailable(), PAGEINSPECT_SKIP_REASON);

it('reads a live row version as current and not dead', function (): void {
    $page = app(HeapPages::class)->explore('public', 'vacuum_page_demo', 0);
    $first = $page->pointers[0];

    expect($first->state)->toBe('normal')
        ->and($first->isDead)->toBeFalse()
        ->and($first->xmax)->toBe('0');
})->skip(fn (): bool => ! pageInspectAvailable(), PAGEINSPECT_SKIP_REASON);

it('refuses a block that is not in the relation', function (): void {
    expect(fn (): mixed => app(HeapPages::class)->explore('public', 'vacuum_page_demo', 99999))
        ->toThrow(InvalidArgumentException::class);
})->skip(fn (): bool => ! pageInspectAvailable(), PAGEINSPECT_SKIP_REASON);

it('refuses a negative block before it ever reaches a system function', function (): void {
    expect(fn (): mixed => app(HeapPages::class)->explore('public', 'vacuum_page_demo', -1))
        ->toThrow(InvalidArgumentException::class);
})->skip(fn (): bool => ! pageInspectAvailable(), PAGEINSPECT_SKIP_REASON);

it('counts the blocks a relation occupies', function (): void {
    expect(app(HeapPages::class)->blockCount('public', 'vacuum_page_demo'))->toBeGreaterThan(0);
})->skip(fn (): bool => ! pageInspectAvailable(), PAGEINSPECT_SKIP_REASON);

it('is disabled when the internals explorers are switched off', function (): void {
    config()->set('vacuum.internals.enabled', false);

    expect(app(HeapPages::class)->availability()->available)->toBeFalse();
});

it('needs the pageinspect extension, not merely a superuser', function (): void {
    DB::statement('DROP EXTENSION IF EXISTS pageinspect');

    // Capabilities is bound as a singleton so a request only probes the
    // server once; forgetting it is what makes this instance re-read the
    // catalog now that the extension is gone, rather than answer from the
    // reading it cached before the drop.
    app()->forgetInstance(Heyosseus\Vacuum\Values\Capabilities::class);

    try {
        expect(app(HeapPages::class)->availability())
            ->available->toBeFalse()
            ->reason->toContain('pageinspect');
    } finally {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pageinspect');
    }
})->skip(fn (): bool => ! pageInspectAvailable(), PAGEINSPECT_SKIP_REASON);

/**
 * pageinspect's raw page functions are restricted to superuser by the
 * extension's own script, regardless of any grant a reader might think to
 * hand out -- this is the case managed PostgreSQL (RDS, Cloud SQL, Azure)
 * puts every reader in, and the message this explorer gives has to be the
 * one that actually explains it.
 */
it('explains that a lesser role cannot use pageinspect even with the extension installed', function (): void {
    $connectionName = DB::getDefaultConnection();
    $original = config("database.connections.{$connectionName}");
    $database = $original['database'];

    DB::statement('DROP ROLE IF EXISTS vacuum_pageinspect_probe');
    DB::statement("CREATE ROLE vacuum_pageinspect_probe LOGIN PASSWORD 'probe'");
    DB::statement("GRANT CONNECT ON DATABASE \"{$database}\" TO vacuum_pageinspect_probe");
    DB::statement('GRANT USAGE ON SCHEMA public TO vacuum_pageinspect_probe');

    try {
        config()->set("database.connections.{$connectionName}.username", 'vacuum_pageinspect_probe');
        config()->set("database.connections.{$connectionName}.password", 'probe');
        app('db')->purge($connectionName);

        expect(app(HeapPages::class)->availability())
            ->available->toBeFalse()
            ->remedy->toContain('superuser');
    } finally {
        config()->set("database.connections.{$connectionName}", $original);
        app('db')->purge($connectionName);

        DB::statement('REVOKE ALL ON SCHEMA public FROM vacuum_pageinspect_probe');
        DB::statement("REVOKE ALL ON DATABASE \"{$database}\" FROM vacuum_pageinspect_probe");
        DB::statement('DROP ROLE IF EXISTS vacuum_pageinspect_probe');
    }
})->skip(fn (): bool => ! pageInspectAvailable(), PAGEINSPECT_SKIP_REASON);

it('finds a page holding a dead line pointer', function (): void {
    DB::statement('DROP TABLE IF EXISTS vacuum_dead_demo');
    DB::statement('CREATE TABLE vacuum_dead_demo (id serial PRIMARY KEY, label text)');
    DB::insert("INSERT INTO vacuum_dead_demo (label) SELECT 'x' || i FROM generate_series(1, 50) i");
    DB::delete('DELETE FROM vacuum_dead_demo WHERE id <= 25');

    // Vacuuming without index cleanup prunes the dead tuples but leaves
    // their line pointers as LP_DEAD placeholders rather than reclaiming
    // them outright, since the primary key index still names them.
    DB::statement('VACUUM (INDEX_CLEANUP FALSE) vacuum_dead_demo');

    expect(app(HeapPages::class)->findInteresting('public', 'vacuum_dead_demo', 'dead'))->not->toBeNull();
})->skip(fn (): bool => ! pageInspectAvailable(), PAGEINSPECT_SKIP_REASON);

it('finds a page holding a HOT chain', function (): void {
    DB::statement('DROP TABLE IF EXISTS vacuum_hot_demo');
    DB::statement('CREATE TABLE vacuum_hot_demo (id serial PRIMARY KEY, label text) WITH (fillfactor = 50)');
    DB::insert("INSERT INTO vacuum_hot_demo (label) SELECT 'x' || i FROM generate_series(1, 50) i");
    DB::update("UPDATE vacuum_hot_demo SET label = label || '!'");

    // The update alone leaves every old tuple LP_NORMAL with its t_ctid
    // pointing at the replacement -- pruning is what turns an
    // index-referenced root into the LP_REDIRECT a chain is read from, and
    // a plain VACUUM is what triggers that pruning.
    DB::statement('VACUUM vacuum_hot_demo');

    expect(app(HeapPages::class)->findInteresting('public', 'vacuum_hot_demo', 'hot'))->not->toBeNull();
})->skip(fn (): bool => ! pageInspectAvailable(), PAGEINSPECT_SKIP_REASON);

it('says nothing found rather than misreading an unknown question', function (): void {
    expect(app(HeapPages::class)->findInteresting('public', 'vacuum_page_demo', 'nonsense'))->toBeNull();
})->skip(fn (): bool => ! pageInspectAvailable(), PAGEINSPECT_SKIP_REASON);

it('bounds the search by the sample limit rather than reading the whole table', function (): void {
    config()->set('vacuum.internals.page_sample_limit', 1);

    // A dead line pointer that only shows up past the sample limit must not
    // be reported: the search is bounded, not exhaustive.
    DB::statement('DROP TABLE IF EXISTS vacuum_dead_demo');
    DB::statement('CREATE TABLE vacuum_dead_demo (id serial PRIMARY KEY, label text)');
    DB::insert("INSERT INTO vacuum_dead_demo (label) SELECT 'x' || i FROM generate_series(1, 2000) i");
    DB::delete('DELETE FROM vacuum_dead_demo WHERE id > 1900');
    DB::statement('VACUUM (INDEX_CLEANUP FALSE) vacuum_dead_demo');

    expect(app(HeapPages::class)->blockCount('public', 'vacuum_dead_demo'))->toBeGreaterThan(1)
        ->and(app(HeapPages::class)->findInteresting('public', 'vacuum_dead_demo', 'dead'))->toBeNull();
})->skip(fn (): bool => ! pageInspectAvailable(), PAGEINSPECT_SKIP_REASON);
