<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Resources;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Heyosseus\Vacuum\Filament\Concerns\AuthorizedByVacuum;
use Heyosseus\Vacuum\Filament\Models\Index as IndexModel;
use Heyosseus\Vacuum\Filament\Resources\IndexResource\Pages\ListIndexes;
use Heyosseus\Vacuum\Support\Bytes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Override;
use UnitEnum;

/**
 * Every index the database keeps, and the two questions worth asking of one: how much
 * does it cost, and does anything read it. An ordinary index nothing has read is pure
 * overhead on every write; a unique or primary one is earning its keep whether or not a
 * query ever names it, so the list says which is which rather than flag them alike.
 */
final class IndexResource extends Resource
{
    use AuthorizedByVacuum;

    protected static ?string $model = IndexModel::class;

    protected static string|UnitEnum|null $navigationGroup = 'Vacuum';

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bars-3-bottom-left';

    protected static ?int $navigationSort = 3;

    #[Override]
    public static function getModelLabel(): string
    {
        return 'index';
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return 'indexes';
    }

    /**
     * Whether an index is unique, backs a primary key, or is even valid is not in
     * pg_stat_user_indexes; nor is its size. The join to pg_index brings the first three,
     * cast to ints so a Postgres 'f' cannot arrive as a truthy string, and
     * pg_relation_size brings the last -- all named so the list can sort and colour by
     * them in SQL.
     *
     * @return Builder<IndexModel>
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return IndexModel::query()
            ->select([
                'pg_stat_user_indexes.schemaname',
                'pg_stat_user_indexes.relname',
                'pg_stat_user_indexes.indexrelname',
                'pg_stat_user_indexes.indexrelid',
                'pg_stat_user_indexes.idx_scan',
            ])
            ->addSelect(new Expression('pg_relation_size(pg_stat_user_indexes.indexrelid) AS index_bytes'))
            ->addSelect(new Expression('pg_index.indisunique::int AS is_unique'))
            ->addSelect(new Expression('pg_index.indisprimary::int AS is_primary'))
            ->addSelect(new Expression('pg_index.indisvalid::int AS is_valid'))
            ->join('pg_index', 'pg_index.indexrelid', '=', 'pg_stat_user_indexes.indexrelid');
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('index_bytes', 'desc')
            ->columns([
                TextColumn::make('indexrelname')
                    ->label('Index')
                    ->description(fn (IndexModel $record): string => $record->schemaname.'.'.$record->relname)
                    ->searchable(['indexrelname', 'relname'])
                    ->sortable(),

                TextColumn::make('index_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn (mixed $state): string => Bytes::human(is_numeric($state) ? (int) $state : 0))
                    ->sortable(),

                TextColumn::make('idx_scan')
                    ->label('Scans')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => number_format(is_numeric($state) ? (int) $state : 0))
                    // Never-read weighs nothing when the index is a constraint the database
                    // enforces on every write, and everything when it is not.
                    ->color(fn (IndexModel $record): string => $record->neverUsed() && ! $record->constrains() ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->state(fn (IndexModel $record): string => match (true) {
                        $record->is_primary => 'primary key',
                        $record->is_unique => 'unique',
                        default => 'index',
                    })
                    ->color(fn (IndexModel $record): string => $record->constrains() ? 'success' : 'gray'),

                TextColumn::make('validity')
                    ->label('State')
                    ->badge()
                    ->state(fn (IndexModel $record): string => $record->is_valid ? 'valid' : 'invalid')
                    ->color(fn (IndexModel $record): string => $record->is_valid ? 'gray' : 'danger'),
            ])
            ->filters([
                Filter::make('unused')
                    ->label('Never read')
                    ->query(fn (Builder $query): Builder => $query->whereRaw('pg_stat_user_indexes.idx_scan = 0')),

                Filter::make('invalid')
                    ->label('Invalid only')
                    ->query(fn (Builder $query): Builder => $query->whereRaw('NOT pg_index.indisvalid')),

                Filter::make('constraints')
                    ->label('Constraints only')
                    ->query(fn (Builder $query): Builder => $query->whereRaw('pg_index.indisunique OR pg_index.indisprimary')),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListIndexes::route('/'),
        ];
    }
}
