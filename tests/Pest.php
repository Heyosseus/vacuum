<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Tests\ConsoleTestCase;
use Heyosseus\Vacuum\Tests\DisabledTestCase;
use Heyosseus\Vacuum\Tests\FilamentTestCase;
use Heyosseus\Vacuum\Tests\FilamentUiTestCase;
use Heyosseus\Vacuum\Tests\HistoryTestCase;
use Heyosseus\Vacuum\Tests\InternalsTestCase;
use Heyosseus\Vacuum\Tests\LearnDisabledTestCase;
use Heyosseus\Vacuum\Tests\TestCase;
use Heyosseus\Vacuum\Values\Setting;

uses(TestCase::class)->in('Feature', 'Unit');

/*
 * The history tests need history switched on before boot and its tables in place, so
 * they run under a case that opts in and migrates around each test.
 */
uses(HistoryTestCase::class)->in('History');

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
 * Same reasoning as the master switch above, scoped to the one page that needs
 * the internals explorers routable rather than merely constructible.
 */
uses(InternalsTestCase::class)->in('Internals');

/*
 * Learn is the other way round -- on by default -- so the case that proves its
 * switch works is the one that turns it off before routes are registered.
 */
uses(LearnDisabledTestCase::class)->in('LearnDisabled');

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

/**
 * A pg_settings row built for a test, without the ceremony of naming every column
 * a rule does not care about. Shared by every configuration-rule test rather than
 * declared once per file, since a global function can only be declared once.
 *
 * $value sets both the session value and the configured one, because that is the
 * ordinary case and it keeps every existing caller reading the way it did. A test
 * about the difference between the two -- which is the whole of C1 -- passes
 * $runtimeValue to make them disagree, exactly as Vacuum's own SET LOCAL does.
 */
function setting(
    string $name,
    string $value,
    string $context = 'user',
    string $source = 'default',
    string $bootValue = '',
    bool $pendingRestart = false,
    ?string $runtimeValue = null,
): Setting {
    return new Setting(
        name: $name,
        value: $runtimeValue ?? $value,
        resetValue: $value,
        unit: null,
        context: $context,
        source: $source,
        bootValue: $bootValue === '' ? $value : $bootValue,
        pendingRestart: $pendingRestart,
    );
}

/** Whether the server under test has pg_stat_statements, which its analytics need. */
function statStatementsInstalled(): bool
{
    return Illuminate\Support\Facades\DB::table('pg_extension')->where('extname', 'pg_stat_statements')->exists();
}

/** The server under test, as PostgreSQL numbers itself: 140012 for 14.12. */
function serverVersionNumber(): int
{
    return (int) Illuminate\Support\Facades\DB::scalar('SHOW server_version_num');
}

/**
 * Make the statistics a test just generated visible to the next read of them.
 *
 * PostgreSQL accumulates statistics in each backend and applies them on its own
 * schedule, so a test that reads pg_stat_user_tables straight after a write sees
 * the state from before it. Every test that asserts on a counter has to close
 * that gap first, and how it closes depends on the server major:
 *
 *   - 15 and up rewrote statistics onto shared memory and shipped
 *     pg_stat_force_next_flush() with it, which does exactly this and does it
 *     exactly. Use it where it exists.
 *
 *   - 14 has no such function -- and calling it there is what took the whole PG14
 *     leg red the day the version matrix was added. Statistics go to a separate
 *     collector process, and a backend only forwards its pending ones at the end
 *     of a transaction, and then only if PGSTAT_STAT_INTERVAL (500ms) has passed
 *     since it last did. So there is nothing to force: waiting out the interval
 *     and then ending one more transaction is the only way to make the backend
 *     hand them over. The trailing clear_snapshot drops this transaction's cached
 *     view so the next read fetches what the collector now holds.
 *
 * This is a test-harness concern and nothing more. Vacuum itself never calls any
 * of it: it reads whatever the server is currently prepared to say, which is the
 * right behaviour on every version.
 */
function flushStatistics(): void
{
    if (serverVersionNumber() >= 150_000) {
        Illuminate\Support\Facades\DB::statement('SELECT pg_stat_force_next_flush()');

        return;
    }

    // Longer than any of those intervals: 14 throttles at 500ms, 15 and up at
    // 1000ms. Waiting past the larger of the two costs a second on the one leg
    // that takes this path, and buys a fallback that can be proven on any server
    // by forcing this branch -- rather than one whose only proof is CI going green
    // on the single version nobody can run locally.
    usleep(1_200_000);

    // A transaction end, now that the interval has elapsed, is what actually makes
    // the backend hand its pending counters over.
    Illuminate\Support\Facades\DB::select('SELECT 1');

    Illuminate\Support\Facades\DB::statement('SELECT pg_stat_clear_snapshot()');
}
