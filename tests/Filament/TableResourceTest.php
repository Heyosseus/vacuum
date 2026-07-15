<?php

declare(strict_types=1);

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Heyosseus\Vacuum\Filament\Models\Table;
use Heyosseus\Vacuum\Filament\Resources\TableResource;
use Heyosseus\Vacuum\Filament\Resources\TableResource\Pages\ListTables;
use Heyosseus\Vacuum\Filament\Resources\TableResource\Pages\ViewTable;
use Heyosseus\Vacuum\Filament\Support\TableProfilePresenter;
use Heyosseus\Vacuum\Vacuum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * The smoke tests prove the Filament surfaces sit on real data. The list is mounted
 * through a genuine panel and rendered. The view page's record binding, profile and
 * findings are exercised against the same PostgreSQL the package reads, so the seam
 * between Filament and Vacuum's core is proven end to end even where Livewire's own
 * nested-component rendering is more than a package test can stand up.
 */

beforeEach(function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);

    // Two thousand rows, then every one updated: two thousand dead against two
    // thousand live is half the table dead, over both the ratio and the minimum, so
    // the advisor raises the dead-tuples finding the page must carry.
    DB::statement('DROP TABLE IF EXISTS crates');
    DB::statement('CREATE TABLE crates (id serial PRIMARY KEY, label text)');
    DB::statement('CREATE INDEX crates_label_index ON crates (label)');
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 2000) i");
    DB::update("UPDATE crates SET label = label || '!'");
    DB::statement('SELECT pg_stat_force_next_flush()');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS crates');
});

/**
 * The list columns, each bound to the crate's row. The whole panel layout is more
 * than a package test can render -- its topbar and notifications are Livewire
 * components of their own -- so the columns are asked for a record directly: the
 * same values the list would show, one seam in from the page.
 *
 * @return array<string, Column>
 */
function listColumns(): array
{
    $page = app(ListTables::class);
    $page->bootedInteractsWithTable();

    $table = $page->getTable();
    $record = TableResource::resolveRecordRouteBinding('public.crates');

    $columns = [];

    foreach ($table->getColumns() as $column) {
        if ($column instanceof Column) {
            $columns[$column->getName()] = $column->record($record);
        }
    }

    return $columns;
}

it('presents a table across the list columns', function (): void {
    $columns = listColumns();

    $size = $columns['total_bytes'];
    $dead = $columns['dead_ratio'];

    expect($columns['relname']->getState())->toBe('crates')
        ->and((string) $columns['relname']->getDescriptionBelow())->toBe('public')
        ->and($size->formatState($size->getState()))->toContain('KB')
        ->and($dead->getState())->toBe('50.0%')
        ->and($dead->getColor($dead->getState()))->toBe('danger');
});

it('paints a healthy table its quiet colour on the list', function (): void {
    // Under the dead-tuple threshold, the badge is gray rather than an alarm.
    DB::statement('DROP TABLE IF EXISTS crates');
    DB::statement('CREATE TABLE crates (id serial PRIMARY KEY, label text)');
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 100) i");
    DB::statement('VACUUM ANALYZE crates');
    DB::statement('SELECT pg_stat_force_next_flush()');

    $dead = listColumns()['dead_ratio'];

    expect($dead->getColor($dead->getState()))->toBe('gray');
});

it('binds the view page to the table its url names', function (): void {
    $record = TableResource::resolveRecordRouteBinding('public.crates');

    expect($record)->toBeInstanceOf(Table::class)
        ->and($record->schemaname)->toBe('public')
        ->and($record->relname)->toBe('crates')
        ->and($record->getRouteKey())->toBe('public.crates');
});

it('gives the bound record its whole profile', function (): void {
    $record = TableResource::resolveRecordRouteBinding('public.crates');

    $rows = TableProfilePresenter::rows($record->profile());

    // Half the table is dead, and it carries its own index beside the primary key.
    expect($rows['dead_rows'])->toBe('2,000 · 50.0%')
        ->and($record->tableIndexes())->toHaveCount(2);
});

it('fills every infolist entry from the bound record', function (): void {
    $record = TableResource::resolveRecordRouteBinding('public.crates');

    // The schema needs a Livewire host to evaluate its entries against; the view page
    // is exactly that host in an application, so it is the one used here too.
    $page = app(ViewTable::class);
    $schema = TableResource::infolist(Schema::make($page)->record($record));

    $states = [];

    // withHidden: false so each section's visibility is actually evaluated -- the
    // findings section only shows when the advisor has something to say, and that
    // question is asked here rather than skipped.
    foreach ($schema->getFlatComponents(withHidden: false) as $component) {
        if ($component instanceof TextEntry) {
            $states[$component->getName()] = $component->getState();
        }
    }

    // The four sizes and the dead-row share come straight from the presenter, and
    // the findings entry carries the advisor's dead-tuples summary.
    expect($states['dead_rows'])->toBe('2,000 · 50.0%')
        ->and($states['heap'])->toContain('KB')
        ->and(implode("\n", (array) $states['findings']))->toContain('tuples are dead');
});

it('shows no findings entry on a healthy table', function (): void {
    // Freshly created, freshly analyzed, nothing dead: the advisor is silent and the
    // section that would list its findings hides itself rather than sit there empty.
    DB::statement('DROP TABLE IF EXISTS crates');
    DB::statement('CREATE TABLE crates (id serial PRIMARY KEY, label text)');
    DB::insert("INSERT INTO crates (label) SELECT 'crate ' || i FROM generate_series(1, 100) i");
    DB::statement('VACUUM ANALYZE crates');
    DB::statement('SELECT pg_stat_force_next_flush()');

    $record = TableResource::resolveRecordRouteBinding('public.crates');

    expect($record->tableFindings())->toBeEmpty();
});

it('has no record to bind for a table that is not there', function (): void {
    expect(TableResource::resolveRecordRouteBinding('public.no_such_table'))->toBeNull();
});

it('lets an authorized visitor through every door the resource has', function (): void {
    $record = TableResource::resolveRecordRouteBinding('public.crates');

    // One callback, every gate: the navigation, the whole resource, and this record.
    expect(TableResource::canAccess())->toBeTrue()
        ->and(TableResource::canViewAny())->toBeTrue()
        ->and(TableResource::canView($record))->toBeTrue()
        ->and(TableResource::getModelLabel())->toBe('table');
});

it('keeps a stranger out of the resource as firmly as out of the dashboard', function (): void {
    Vacuum::auth(static fn (Request $request): bool => false);

    $record = TableResource::resolveRecordRouteBinding('public.crates');

    expect(TableResource::canAccess())->toBeFalse()
        ->and(TableResource::canViewAny())->toBeFalse()
        ->and(TableResource::canView($record))->toBeFalse();
});
