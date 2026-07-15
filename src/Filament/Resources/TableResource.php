<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Resources;

use BackedEnum;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Heyosseus\Vacuum\Filament\Concerns\AuthorizedByVacuum;
use Heyosseus\Vacuum\Filament\Models\Table as TableModel;
use Heyosseus\Vacuum\Filament\Resources\TableResource\Pages\ListTables;
use Heyosseus\Vacuum\Filament\Resources\TableResource\Pages\ViewTable;
use Heyosseus\Vacuum\Filament\Support\TableProfilePresenter;
use Heyosseus\Vacuum\Support\Bytes;
use Heyosseus\Vacuum\Values\IndexStatistic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Config;
use Override;
use UnitEnum;

/**
 * The tables surface, as a real Filament resource rather than a page dressed up as one.
 * Because it is backed by an Eloquent model over pg_stat_user_tables, the list's sorting,
 * searching, filtering and paging are Filament's own and run in PostgreSQL. The
 * drill-down then resolves the same rich profile the Blade page shows and hands it,
 * unchanged, to an infolist.
 */
final class TableResource extends Resource
{
    use AuthorizedByVacuum;

    protected static ?string $model = TableModel::class;

    protected static string|UnitEnum|null $navigationGroup = 'Vacuum';

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'relname';

    #[Override]
    public static function getModelLabel(): string
    {
        return 'table';
    }

    /**
     * The total on-disk size is not in pg_stat_user_tables, so it is computed here, on
     * Filament's own query, and the list can order and filter by it in SQL.
     *
     * @return Builder<TableModel>
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        // The base columns must be named alongside the computed one: adding a raw select
        // to a query that had none replaces the implicit `*` rather than appending to it,
        // and the row would come back as nothing but its size.
        return TableModel::query()
            ->select('pg_stat_user_tables.*')
            ->addSelect(new Expression('pg_total_relation_size(pg_stat_user_tables.relid) AS total_bytes'));
    }

    #[Override]
    public static function table(Table $table): Table
    {
        $threshold = self::deadTupleThreshold();

        return $table
            ->defaultSort('total_bytes', 'desc')
            ->columns([
                TextColumn::make('relname')
                    ->label('Table')
                    ->description(fn (TableModel $record): string => $record->schemaname)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('total_bytes')
                    ->label('Total size')
                    ->formatStateUsing(fn (mixed $state): string => Bytes::human(is_numeric($state) ? (int) $state : 0))
                    ->sortable(),

                TextColumn::make('n_live_tup')
                    ->label('Rows')
                    ->formatStateUsing(fn (mixed $state): string => number_format(is_numeric($state) ? (int) $state : 0))
                    ->sortable(),

                TextColumn::make('dead_ratio')
                    ->label('Dead rows')
                    ->badge()
                    ->state(fn (TableModel $record): string => number_format($record->deadTupleRatio() * 100, 1).'%')
                    ->color(fn (TableModel $record): string => $record->deadTupleRatio() >= $threshold ? 'danger' : 'gray'),

                TextColumn::make('seq_share')
                    ->label('Seq scans')
                    ->badge()
                    ->state(fn (TableModel $record): string => self::share($record->sequentialShare()))
                    // A table read only by scanning it whole is the shape of a missing
                    // index; one the planner reaches into by key is doing the right thing.
                    ->color(fn (TableModel $record): string => ($record->sequentialShare() ?? 0.0) >= 0.5 ? 'warning' : 'gray'),

                TextColumn::make('last_autovacuum')
                    ->label('Last vacuum')
                    ->since()
                    ->placeholder('never')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('schemaname')
                    ->label('Schema')
                    ->options(fn (): array => self::schemas()),

                Filter::make('dead_heavy')
                    ->label('Dead-heavy')
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        'pg_stat_user_tables.n_dead_tup >= ? AND pg_stat_user_tables.n_dead_tup::float8 / greatest(pg_stat_user_tables.n_live_tup + pg_stat_user_tables.n_dead_tup, 1) >= ?',
                        [self::deadTupleMinimum(), $threshold],
                    )),

                Filter::make('never_vacuumed')
                    ->label('Never vacuumed')
                    ->query(fn (Builder $query): Builder => $query->whereNull('pg_stat_user_tables.last_vacuum')->whereNull('pg_stat_user_tables.last_autovacuum')),

                Filter::make('never_analyzed')
                    ->label('Never analyzed')
                    ->query(fn (Builder $query): Builder => $query->whereNull('pg_stat_user_tables.last_analyze')->whereNull('pg_stat_user_tables.last_autoanalyze')),
            ]);
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('This table')
                ->schema([
                    TextEntry::make('rows')->state(self::fact('rows')),
                    TextEntry::make('dead_rows')->label('Dead rows')->state(self::fact('dead_rows')),
                    TextEntry::make('heap')->state(self::fact('heap')),
                    TextEntry::make('indexes')->state(self::fact('indexes')),
                    TextEntry::make('toast')->label('TOAST')->state(self::fact('toast')),
                    TextEntry::make('freeze_age')->label('Freeze age')->state(self::fact('freeze_age')),
                ])
                ->columns(3),

            Section::make('How it is read')
                ->schema([
                    TextEntry::make('sequential_scans')->label('Sequential scans')->state(self::fact('sequential_scans')),
                    TextEntry::make('index_scans')->label('Index scans')->state(self::fact('index_scans')),
                    TextEntry::make('rows_read_scanning')->label('Rows read by scanning')->state(self::fact('rows_read_scanning')),
                    TextEntry::make('rows_found_by_index')->label('Rows found by index')->state(self::fact('rows_found_by_index')),
                ])
                ->columns(2),

            Section::make('How it is written')
                ->schema([
                    TextEntry::make('inserts')->state(self::fact('inserts')),
                    TextEntry::make('updates')->state(self::fact('updates')),
                    TextEntry::make('deletes')->state(self::fact('deletes')),
                    TextEntry::make('hot_updates')->label('HOT updates')->state(self::fact('hot_updates')),
                ])
                ->columns(2),

            Section::make('Autovacuum')
                ->schema([
                    TextEntry::make('last_vacuum')->label('Last vacuum')->state(self::fact('last_vacuum')),
                    TextEntry::make('last_analyze')->label('Last analyze')->state(self::fact('last_analyze')),
                    TextEntry::make('vacuums_at')->label('Vacuums at')->state(self::fact('vacuums_at')),
                    TextEntry::make('analyzes_at')->label('Analyzes at')->state(self::fact('analyzes_at')),
                    TextEntry::make('autovacuum')->label('Tuning')->state(self::fact('autovacuum')),
                ])
                ->columns(2),

            Section::make('Indexes')
                ->visible(fn (TableModel $record): bool => $record->tableIndexes() !== [])
                ->schema([
                    TextEntry::make('index_list')
                        ->hiddenLabel()
                        ->listWithLineBreaks()
                        ->state(fn (TableModel $record): array => array_map(
                            static fn (IndexStatistic $index): string => sprintf(
                                '%s — %s%s',
                                $index->name,
                                Bytes::human($index->bytes),
                                $index->neverUsed() && ! $index->constrains() ? ' · never read' : '',
                            ),
                            $record->tableIndexes(),
                        )),
                ]),

            Section::make('What Vacuum thinks')
                ->visible(fn (TableModel $record): bool => $record->tableFindings() !== [])
                ->schema([
                    TextEntry::make('findings')
                        ->hiddenLabel()
                        ->listWithLineBreaks()
                        ->state(fn (TableModel $record): array => array_map(
                            static fn (\Heyosseus\Vacuum\Advisor\Finding $finding): string => $finding->summary,
                            $record->tableFindings(),
                        )),
                ]),
        ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTables::route('/'),
            'view' => ViewTable::route('/{record}'),
        ];
    }

    /**
     * One presenter formats every number, so an entry's state is a lookup and the schema
     * above stays a list of what exists rather than how anything is spelled.
     */
    private static function fact(string $key): Closure
    {
        return static fn (TableModel $record): string => TableProfilePresenter::rows($record->profile())[$key];
    }

    /** A share as a whole-number percentage, or a dash where nothing has read the table. */
    private static function share(?float $share): string
    {
        return $share === null ? '—' : number_format($share * 100, 0).'%';
    }

    /**
     * The schemas that actually hold tables, for the filter's options. Built from the
     * list itself so the choices are the ones a reader could act on, keyed to their own
     * names because that is what the filter matches against.
     *
     * @return array<string, string>
     */
    private static function schemas(): array
    {
        $schemas = [];

        $rows = TableModel::query()
            ->select('pg_stat_user_tables.schemaname')
            ->distinct()
            ->orderBy('pg_stat_user_tables.schemaname')
            ->get();

        foreach ($rows as $row) {
            $schemas[$row->schemaname] = $row->schemaname;
        }

        return $schemas;
    }

    private static function deadTupleThreshold(): float
    {
        $configured = Config::get('vacuum.thresholds.dead_tuple_ratio', 0.20);

        return is_numeric($configured) ? (float) $configured : 0.20;
    }

    private static function deadTupleMinimum(): int
    {
        $configured = Config::get('vacuum.thresholds.dead_tuple_minimum', 1_000);

        return is_numeric($configured) ? (int) $configured : 1_000;
    }
}
