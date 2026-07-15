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
use Heyosseus\Vacuum\Filament\Models\Session as SessionModel;
use Heyosseus\Vacuum\Filament\Resources\SessionResource\Pages\ListSessions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Override;
use UnitEnum;

/**
 * What every connection to the database is doing at the moment you look. The list polls,
 * because the answer changes under you: a transaction that was idle a second ago is the
 * one now blocking three others. The durations are PostgreSQL's own arithmetic, counted
 * against the clock the transaction started on rather than the application server's.
 */
final class SessionResource extends Resource
{
    use AuthorizedByVacuum;

    protected static ?string $model = SessionModel::class;

    protected static string|UnitEnum|null $navigationGroup = 'Vacuum';

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static ?int $navigationSort = 4;

    #[Override]
    public static function getModelLabel(): string
    {
        return 'session';
    }

    /**
     * The ages and the blocking pids are worked out here, in SQL, so the list can sort by
     * how long a transaction has been open without PHP ever guessing at a duration.
     *
     * @return Builder<SessionModel>
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return SessionModel::query()
            ->select(['pg_stat_activity.pid'])
            ->addSelect(new Expression("coalesce(pg_stat_activity.usename, '') AS usename"))
            ->addSelect(new Expression("coalesce(pg_stat_activity.application_name, '') AS application_name"))
            ->addSelect(new Expression("coalesce(pg_stat_activity.state, '') AS state"))
            ->addSelect(new Expression("coalesce(pg_stat_activity.query, '') AS query"))
            ->addSelect(new Expression('coalesce(extract(epoch FROM (now() - pg_stat_activity.xact_start)), 0)::int AS transaction_seconds'))
            ->addSelect(new Expression('coalesce(extract(epoch FROM (now() - pg_stat_activity.state_change)), 0)::int AS state_seconds'))
            ->addSelect(new Expression("array_to_string(pg_blocking_pids(pg_stat_activity.pid), ',') AS blocked_by"));
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->defaultSort('transaction_seconds', 'desc')
            ->columns([
                TextColumn::make('pid')
                    ->label('PID')
                    ->sortable(),

                TextColumn::make('usename')
                    ->label('User')
                    ->description(fn (SessionModel $record): string => $record->application_name)
                    ->searchable(['usename', 'application_name']),

                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (SessionModel $record): string => match (true) {
                        $record->state === 'active' => 'success',
                        $record->state === 'idle in transaction' => 'warning',
                        $record->state === 'idle in transaction (aborted)' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('transaction_seconds')
                    ->label('In transaction')
                    ->formatStateUsing(fn (mixed $state): string => self::duration(is_numeric($state) ? (int) $state : 0))
                    ->sortable(),

                TextColumn::make('blocked_by')
                    ->label('Blocked by')
                    ->badge()
                    ->color('danger')
                    ->placeholder('—'),

                TextColumn::make('query')
                    ->label('Query')
                    ->limit(60)
                    ->tooltip(fn (SessionModel $record): string => $record->query)
                    ->wrap(),
            ])
            ->filters([
                Filter::make('active')
                    ->query(fn (Builder $query): Builder => $query->whereRaw("pg_stat_activity.state = 'active'")),

                Filter::make('idle_in_transaction')
                    ->label('Idle in transaction')
                    ->query(fn (Builder $query): Builder => $query->whereRaw("pg_stat_activity.state IN ('idle in transaction', 'idle in transaction (aborted)')")),

                Filter::make('blocked')
                    ->query(fn (Builder $query): Builder => $query->whereRaw('cardinality(pg_blocking_pids(pg_stat_activity.pid)) > 0')),
            ]);
    }

    /**
     * Seconds as the largest two units that say something: "2h 5m", "3m 12s", "45s".
     * Zero reads as a dash rather than "0s", because a session with no open transaction
     * has no age to report, not an age of nothing.
     */
    private static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        if ($minutes > 0) {
            return "{$minutes}m {$rest}s";
        }

        return "{$rest}s";
    }

    /**
     * @return array<string, PageRegistration>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSessions::route('/'),
        ];
    }
}
