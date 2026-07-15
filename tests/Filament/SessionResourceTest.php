<?php

declare(strict_types=1);

use Filament\Tables\Filters\Filter;
use Heyosseus\Vacuum\Filament\Models\Session;
use Heyosseus\Vacuum\Filament\Resources\SessionResource;
use Heyosseus\Vacuum\Filament\Resources\SessionResource\Pages\ListSessions;
use Heyosseus\Vacuum\Vacuum;
use Illuminate\Http\Request;

/*
 * The sessions surface reads pg_stat_activity live. The test's own connection is a
 * session, so the list has at least itself to show; the state colours and the age format
 * are driven with made-up records and values, because a test cannot conjure an idle or a
 * blocked backend on demand.
 */

beforeEach(function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);
});

/** A backend in a given state, without needing the database to hold one. */
function fakeSession(string $state): Session
{
    $session = new Session;

    $session->setRawAttributes([
        'pid' => 42,
        'usename' => 'vacuum',
        'application_name' => 'test',
        'state' => $state,
        'query' => 'SELECT 1',
        'transaction_seconds' => 0,
        'state_seconds' => 0,
        'blocked_by' => '',
    ]);

    return $session;
}

it('shows the live session it is itself running in', function (): void {
    $record = SessionResource::getEloquentQuery()->firstOrFail();

    $columns = bootedColumns(ListSessions::class, $record);

    expect($columns['pid']->getState())->toBeInt();

    foreach ($columns as $column) {
        exerciseColumn($column);
    }

    // The model's reading of its own state, which the filters lean on.
    expect($record->active())->toBeBool()
        ->and($record->idleInTransaction())->toBeBool()
        ->and($record->blocked())->toBeBool();
});

it('paints each session state its own colour', function (): void {
    $colours = [];

    foreach (['active', 'idle in transaction', 'idle in transaction (aborted)', 'idle'] as $state) {
        $column = bootedColumns(ListSessions::class, fakeSession($state))['state'];
        $colours[$state] = $column->getColor($column->getState());
    }

    expect($colours['active'])->toBe('success')
        ->and($colours['idle in transaction'])->toBe('warning')
        ->and($colours['idle in transaction (aborted)'])->toBe('danger')
        ->and($colours['idle'])->toBe('gray');
});

it('reads a transaction age as the two units that say something', function (): void {
    $age = bootedColumns(ListSessions::class, fakeSession('active'))['transaction_seconds'];

    expect($age->formatState(0))->toBe('—')
        ->and($age->formatState(7_200))->toBe('2h 0m')
        ->and($age->formatState(150))->toBe('2m 30s')
        ->and($age->formatState(42))->toBe('42s');
});

it('filters the sessions to the ones worth watching', function (): void {
    $page = app(ListSessions::class);
    $page->bootedInteractsWithTable();

    $applied = 0;

    foreach ($page->getTable()->getFilters() as $filter) {
        if ($filter instanceof Filter) {
            $query = SessionResource::getEloquentQuery();
            $filter->apply($query, ['isActive' => true]);
            $query->get();
            $applied++;
        }
    }

    // Active, idle-in-transaction and blocked, each exercised against real SQL.
    expect($applied)->toBe(3);
});

it('gives the session resource its label and a single list page', function (): void {
    expect(SessionResource::getModelLabel())->toBe('session')
        ->and(SessionResource::canAccess())->toBeTrue()
        ->and(array_keys(SessionResource::getPages()))->toBe(['index']);
});
