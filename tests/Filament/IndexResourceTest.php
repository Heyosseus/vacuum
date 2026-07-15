<?php

declare(strict_types=1);

use Filament\Tables\Filters\Filter;
use Heyosseus\Vacuum\Filament\Models\Index;
use Heyosseus\Vacuum\Filament\Resources\IndexResource;
use Heyosseus\Vacuum\Filament\Resources\IndexResource\Pages\ListIndexes;
use Heyosseus\Vacuum\Vacuum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * The indexes surface reads pg_stat_user_indexes joined to pg_index. The crates table is
 * given one of each kind the list distinguishes -- a primary key, a plain index and a
 * unique index -- so the badge that names them is proven against all three.
 */

beforeEach(function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);

    DB::statement('DROP TABLE IF EXISTS crates');
    DB::statement('CREATE TABLE crates (id serial PRIMARY KEY, label text, code text)');
    DB::statement('CREATE INDEX crates_label_index ON crates (label)');
    DB::statement('CREATE UNIQUE INDEX crates_code_unique ON crates (code)');
    DB::insert("INSERT INTO crates (label, code) SELECT 'c' || i, 'k' || i FROM generate_series(1, 50) i");
    DB::statement('SELECT pg_stat_force_next_flush()');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
});

function indexNamed(string $name): Index
{
    return IndexResource::getEloquentQuery()
        ->where('pg_stat_user_indexes.indexrelname', $name)
        ->firstOrFail();
}

it('names each index by what it is', function (): void {
    $primary = bootedColumns(ListIndexes::class, indexNamed('crates_pkey'))['kind'];
    $unique = bootedColumns(ListIndexes::class, indexNamed('crates_code_unique'))['kind'];
    $plain = bootedColumns(ListIndexes::class, indexNamed('crates_label_index'))['kind'];

    expect($primary->getState())->toBe('primary key')
        ->and($primary->getColor($primary->getState()))->toBe('success')
        ->and($unique->getState())->toBe('unique')
        ->and($plain->getState())->toBe('index')
        ->and($plain->getColor($plain->getState()))->toBe('gray');
});

it('paints a plain index nothing has read as a cost, and a valid one quiet', function (): void {
    $record = indexNamed('crates_label_index');
    $columns = bootedColumns(ListIndexes::class, $record);

    $scans = $columns['idx_scan'];
    $size = $columns['index_bytes'];
    $validity = $columns['validity'];

    expect($scans->getColor($scans->getState()))->toBe('danger')
        ->and($size->formatState($size->getState()))->toContain('B')
        ->and($validity->getState())->toBe('valid')
        ->and($validity->getColor($validity->getState()))->toBe('gray');

    foreach ($columns as $column) {
        exerciseColumn($column);
    }

    // The model's own reading of itself, which the list leans on.
    expect($record->neverUsed())->toBeTrue()
        ->and($record->constrains())->toBeFalse()
        ->and(indexNamed('crates_pkey')->constrains())->toBeTrue();
});

it('filters the indexes down to the ones worth acting on', function (): void {
    $page = app(ListIndexes::class);
    $page->bootedInteractsWithTable();

    $applied = 0;

    foreach ($page->getTable()->getFilters() as $filter) {
        if ($filter instanceof Filter) {
            $query = IndexResource::getEloquentQuery();
            $filter->apply($query, ['isActive' => true]);
            $query->get();
            $applied++;
        }
    }

    // Never-read, invalid-only and constraints-only, each exercised against real SQL.
    expect($applied)->toBe(3);
});

it('gives the index resource its labels and a single list page', function (): void {
    expect(IndexResource::getModelLabel())->toBe('index')
        ->and(IndexResource::getPluralModelLabel())->toBe('indexes')
        ->and(IndexResource::canAccess())->toBeTrue()
        ->and(array_keys(IndexResource::getPages()))->toBe(['index']);
});
