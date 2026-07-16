<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Filament\Models\Statement;
use Heyosseus\Vacuum\Filament\Resources\StatementResource;
use Heyosseus\Vacuum\Filament\Resources\StatementResource\Pages\ListStatements;
use Heyosseus\Vacuum\Vacuum;
use Heyosseus\Vacuum\Values\Capabilities;
use Illuminate\Http\Request;

/*
 * The statements surface reads pg_stat_statements, which is optional. Everything that
 * does not need the extension -- the timing format, the gate that hides the surface when
 * the extension is absent -- is proven with a made-up row and a stubbed capability set,
 * so the coverage does not depend on a server that happens to have it, only the one test
 * that reads the real view.
 */

beforeEach(function (): void {
    Vacuum::auth(static fn (Request $request): bool => true);
});

/** A statement row, without needing pg_stat_statements to hold one. */
function fakeStatement(): Statement
{
    $statement = new Statement;

    $statement->setRawAttributes([
        'queryid' => '123',
        'query' => 'SELECT * FROM orders WHERE id = $1',
        'calls' => 12,
        'total_exec_time' => 4_200.0,
        'mean_exec_time' => 600.0,
        'rows' => 12,
    ]);

    return $statement;
}

it('reads a timing in the unit that says something', function (): void {
    $columns = bootedColumns(ListStatements::class, fakeStatement());

    $total = $columns['total_exec_time'];
    $mean = $columns['mean_exec_time'];

    expect($total->formatState(340))->toContain('ms')
        ->and($total->formatState(1_800))->toContain('s')
        ->and($total->formatState(120_000))->toContain('min')
        // A mean over half a second is worth an amber badge.
        ->and($mean->getColor($mean->getState()))->toBe('warning');

    foreach ($columns as $column) {
        exerciseColumn($column);
    }
});

it('hides the surface where pg_stat_statements is not installed', function (): void {
    $preloaded = ['shared_preload_libraries' => 'pg_stat_statements'];

    app()->instance(Capabilities::class, new Capabilities(170_000, ['pg_stat_statements'], $preloaded, true));

    expect(StatementResource::canAccess())->toBeTrue()
        ->and(StatementResource::canViewAny())->toBeTrue();

    app()->instance(Capabilities::class, new Capabilities(170_000, ['plpgsql'], $preloaded, true));

    expect(StatementResource::canAccess())->toBeFalse();

    // Created but never preloaded leaves a view that throws rather than answers,
    // so the surface stays hidden rather than 500 on its first read.
    app()->instance(Capabilities::class, new Capabilities(170_000, ['pg_stat_statements'], ['shared_preload_libraries' => 'auto_explain'], true));

    expect(StatementResource::canAccess())->toBeFalse();

    // A stranger is out even where the extension works.
    Vacuum::auth(static fn (Request $request): bool => false);
    app()->instance(Capabilities::class, new Capabilities(170_000, ['pg_stat_statements'], $preloaded, true));

    expect(StatementResource::canAccess())->toBeFalse();
});

it('gives the statement resource its label and a single list page', function (): void {
    expect(StatementResource::getModelLabel())->toBe('statement')
        ->and(array_keys(StatementResource::getPages()))->toBe(['index']);
});

/**
 * The model declares queryid as its primary key, which is only true of an
 * aggregated read: the raw view keys on (userid, dbid, queryid, toplevel), so the
 * same statement run by two roles gave Eloquent two records claiming one identity
 * — and a paged, sorted table built on a non-unique key silently repeats and skips
 * rows. Grouping in the model's own query is what makes the declared key honest.
 */
it('gives every record a queryid that identifies exactly one of them', function (): void {
    Illuminate\Support\Facades\DB::select('SELECT 1');

    $ids = StatementResource::getEloquentQuery()->pluck('queryid');

    expect($ids)->not->toBeEmpty()
        ->and($ids->count())->toBe($ids->unique()->count());
})->skip(fn (): bool => ! statStatementsInstalled(), 'pg_stat_statements is not installed on this server.');

it('counts each statement once, so paging does not repeat or skip rows', function (): void {
    Illuminate\Support\Facades\DB::select('SELECT 1');

    // The count the paginator uses and the number of distinct statements have to
    // be the same number, or the last page is a lie.
    $counted = StatementResource::getEloquentQuery()->count();
    $distinct = StatementResource::getEloquentQuery()->pluck('queryid')->unique()->count();

    expect($counted)->toBe($distinct);
})->skip(fn (): bool => ! statStatementsInstalled(), 'pg_stat_statements is not installed on this server.');

it('reads the real statements when the extension is present', function (): void {
    // A query the view will have watched, kept out of the surface's own reading of it.
    Illuminate\Support\Facades\DB::select('SELECT 1');

    $record = StatementResource::getEloquentQuery()->first();

    expect($record)->not->toBeNull();

    foreach (bootedColumns(ListStatements::class, $record) as $column) {
        exerciseColumn($column);
    }
})->skip(fn (): bool => ! statStatementsInstalled(), 'pg_stat_statements is not installed on this server.');
