<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Resources;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Heyosseus\Vacuum\Filament\Concerns\AuthorizedByVacuum;
use Heyosseus\Vacuum\Filament\Models\Statement as StatementModel;
use Heyosseus\Vacuum\Filament\Resources\StatementResource\Pages\ListStatements;
use Heyosseus\Vacuum\Vacuum;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Database\Eloquent\Builder;
use Override;
use UnitEnum;

/**
 * The shapes of query the database has spent the most time on, read from
 * pg_stat_statements. Each row is a normalised statement -- every call with the
 * parameters stripped, summed as one -- so the totals mean something and the text is
 * deliberately unrunnable.
 *
 * The extension is optional, and where it is missing this surface hides itself rather
 * than render a grid of zeroes or reach for a view that is not there.
 */
final class StatementResource extends Resource
{
    use AuthorizedByVacuum;

    protected static ?string $model = StatementModel::class;

    protected static string|UnitEnum|null $navigationGroup = 'Vacuum';

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 5;

    #[Override]
    public static function getModelLabel(): string
    {
        return 'statement';
    }

    /** The surface exists only when pg_stat_statements does; without it there is nothing to read. */
    #[Override]
    public static function canAccess(): bool
    {
        return Vacuum::check(request()) && self::available();
    }

    #[Override]
    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    private static function available(): bool
    {
        return app(Capabilities::class)->has('pg_stat_statements');
    }

    /**
     * @return Builder<StatementModel>
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return StatementModel::query();
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('total_exec_time', 'desc')
            ->columns([
                TextColumn::make('query')
                    ->label('Statement')
                    ->limit(80)
                    ->tooltip(fn (StatementModel $record): string => $record->query)
                    ->wrap()
                    ->searchable(),

                TextColumn::make('calls')
                    ->label('Calls')
                    ->formatStateUsing(fn (mixed $state): string => number_format(is_numeric($state) ? (int) $state : 0))
                    ->sortable(),

                TextColumn::make('total_exec_time')
                    ->label('Total time')
                    ->formatStateUsing(fn (mixed $state): string => self::milliseconds(is_numeric($state) ? (float) $state : 0.0))
                    ->sortable(),

                TextColumn::make('mean_exec_time')
                    ->label('Mean')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::milliseconds(is_numeric($state) ? (float) $state : 0.0))
                    ->color(fn (StatementModel $record): string => $record->mean_exec_time >= 500 ? 'warning' : 'gray')
                    ->sortable(),

                TextColumn::make('rows')
                    ->label('Rows')
                    ->formatStateUsing(fn (mixed $state): string => number_format(is_numeric($state) ? (int) $state : 0))
                    ->sortable(),
            ]);
    }

    /**
     * Milliseconds, shown in the unit that reads: "340 ms" under a second, "1.8 s" over
     * it, "2.5 min" once even seconds stop being legible.
     */
    private static function milliseconds(float $milliseconds): string
    {
        if ($milliseconds < 1000) {
            return number_format($milliseconds).' ms';
        }

        $seconds = $milliseconds / 1000;

        if ($seconds < 90) {
            return number_format($seconds, 1).' s';
        }

        return number_format($seconds / 60, 1).' min';
    }

    /**
     * @return array<string, PageRegistration>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListStatements::route('/'),
        ];
    }
}
