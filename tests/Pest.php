<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Tests\ConsoleTestCase;
use Heyosseus\Vacuum\Tests\DisabledTestCase;
use Heyosseus\Vacuum\Tests\FilamentTestCase;
use Heyosseus\Vacuum\Tests\FilamentUiTestCase;
use Heyosseus\Vacuum\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/*
 * The master switch, the console switch and the UI mode are all read while routes
 * are being registered, before any test body runs, so the tests that prove they
 * work have to boot a differently configured application rather than flip a config
 * value.
 */
uses(DisabledTestCase::class)->in('Disabled');
uses(ConsoleTestCase::class)->in('Console');
uses(FilamentUiTestCase::class)->in('FilamentUi');

/*
 * The smoke tests boot a real Filament panel with Vacuum's plugin on it, so they
 * live under their own case rather than the bare package one.
 */
uses(FilamentTestCase::class)->in('Filament');

/**
 * Call a protected method -- a widget's getStats or getData, a page's getHeaderWidgets --
 * the way Filament itself would from inside the class, so the render-free tests can reach
 * what a full page render would otherwise be the only way to run.
 *
 * @param  array<int, mixed>  $arguments
 */
function invokeProtected(object $object, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($object, $method);

    return $reflection->invokeArgs($object, $arguments);
}

/**
 * The columns of a resource's list, each bound to one record, so their state, colour,
 * description and tooltip closures can be evaluated one seam in from a page render the
 * panel chrome is too much to stand up in a package test.
 *
 * @return array<string, Filament\Tables\Columns\Column>
 */
function bootedColumns(string $listPage, ?Illuminate\Database\Eloquent\Model $record): array
{
    $page = app($listPage);
    $page->bootedInteractsWithTable();

    $columns = [];

    foreach ($page->getTable()->getColumns() as $column) {
        if ($column instanceof Filament\Tables\Columns\Column) {
            $columns[$column->getName()] = $record instanceof Illuminate\Database\Eloquent\Model
                ? $column->record($record)
                : $column;
        }
    }

    return $columns;
}

/**
 * Run every closure a column carries, so a column whose only logic is a colour or a
 * tooltip is covered without an assertion contorted to name it.
 */
function exerciseColumn(Filament\Tables\Columns\Column $column): void
{
    $state = $column->getState();

    $column->getColor($state);
    $column->getDescriptionAbove();
    $column->getDescriptionBelow();
    $column->getTooltip($state);
}

/** Whether the server under test has pg_stat_statements, which its analytics need. */
function statStatementsInstalled(): bool
{
    return Illuminate\Support\Facades\DB::table('pg_extension')->where('extname', 'pg_stat_statements')->exists();
}
