<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Internals\Explorers\RowVersions;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS vacuum_rowversion_demo');
    DB::statement('CREATE TABLE vacuum_rowversion_demo (id serial PRIMARY KEY, label text)');
    DB::insert("INSERT INTO vacuum_rowversion_demo (label) VALUES ('first'), ('second')");
    config()->set('vacuum.internals.enabled', true);
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS vacuum_rowversion_demo');
});

it('shows where each row version physically lives and which transaction made it', function (): void {
    $versions = app(RowVersions::class)->explore('public', 'vacuum_rowversion_demo');

    expect($versions)->toHaveCount(2)
        ->and($versions[0]->block)->toBe(0)
        ->and($versions[0]->xmin)->not->toBe('')
        ->and($versions[0]->isCurrent)->toBeTrue();
});

it('is available on any server, needing no extension at all', function (): void {
    expect(app(RowVersions::class)->availability()->available)->toBeTrue();
});

it('is unavailable when the internals explorers are switched off', function (): void {
    config()->set('vacuum.internals.enabled', false);

    expect(app(RowVersions::class)->availability()->available)->toBeFalse();
});

it('refuses to build a statement around a relation the catalog does not know', function (): void {
    expect(fn (): mixed => app(RowVersions::class)->explore('public', 'no_such_table'))
        ->toThrow(InvalidArgumentException::class);
});
