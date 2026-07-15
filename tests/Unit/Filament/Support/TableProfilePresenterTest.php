<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Filament\Support\TableProfilePresenter;
use Heyosseus\Vacuum\Values\TableProfile;

/**
 * The presenter is the single place a TableProfile's numbers become the strings a
 * person reads, so the ViewTable page can declare which rows exist and nothing
 * more. Two fixtures drive it: a busy table where every ratio and timestamp has a
 * value, and a brand-new one where none of them do, so both sides of every
 * "or never / or none / or dash" are proven.
 */
function busyTable(): TableProfile
{
    return new TableProfile(
        schema: 'public',
        name: 'orders',
        liveTuples: 1_000_000,
        deadTuples: 250_000,
        modificationsSinceAnalyze: 5_000,
        xidAge: 120_000_000,
        heapBytes: 500 * 1024 * 1024,
        indexBytes: 200 * 1024 * 1024,
        toastBytes: 8 * 1024 * 1024,
        totalBytes: 708 * 1024 * 1024,
        sequentialScans: 300,
        sequentialTuplesRead: 9_000_000,
        indexScans: 700,
        indexTuplesFetched: 40_000,
        inserts: 80_000,
        updates: 20_000,
        hotUpdates: 5_000,
        deletes: 1_000,
        lastVacuum: CarbonImmutable::parse('2026-07-14 12:00:00'),
        lastAutovacuum: null,
        lastAnalyze: CarbonImmutable::parse('2026-07-14 12:00:00'),
        lastAutoanalyze: null,
        vacuumScaleFactor: 0.2,
        vacuumThreshold: 50,
        analyzeScaleFactor: 0.1,
        analyzeThreshold: 50,
        tuned: true,
    );
}

/** A brand-new table nothing has touched: no reads, no updates, never vacuumed. */
function quietTable(): TableProfile
{
    return new TableProfile(
        schema: 'public',
        name: 'orders',
        liveTuples: 0,
        deadTuples: 0,
        modificationsSinceAnalyze: 0,
        xidAge: 120_000_000,
        heapBytes: 500 * 1024 * 1024,
        indexBytes: 200 * 1024 * 1024,
        toastBytes: 0,
        totalBytes: 700 * 1024 * 1024,
        sequentialScans: 0,
        sequentialTuplesRead: 0,
        indexScans: 0,
        indexTuplesFetched: 0,
        inserts: 0,
        updates: 0,
        hotUpdates: 0,
        deletes: 0,
        lastVacuum: null,
        lastAutovacuum: null,
        lastAnalyze: null,
        lastAutoanalyze: null,
        vacuumScaleFactor: 0.2,
        vacuumThreshold: 50,
        analyzeScaleFactor: 0.1,
        analyzeThreshold: 50,
        tuned: false,
    );
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 12:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('names the four sizes the way a person would say them', function (): void {
    $rows = TableProfilePresenter::rows(busyTable());

    expect($rows['heap'])->toBe('500.0 MB')
        ->and($rows['indexes'])->toBe('200.0 MB')
        ->and($rows['toast'])->toBe('8.0 MB')
        ->and($rows['rows'])->toBe('1,000,000');
});

it('says none rather than zero bytes for a table with no TOAST relation', function (): void {
    expect(TableProfilePresenter::rows(quietTable())['toast'])->toBe('none');
});

it('shows dead rows next to the share they are of the table', function (): void {
    expect(TableProfilePresenter::rows(busyTable())['dead_rows'])->toBe('250,000 · 20.0%');
});

it('counts the freeze age in transactions', function (): void {
    expect(TableProfilePresenter::rows(busyTable())['freeze_age'])->toBe('120,000,000 txns');
});

it('notes the share of reads that scanned the whole table', function (): void {
    expect(TableProfilePresenter::rows(busyTable())['sequential_scans'])->toBe('300 · 30.0% of reads');
});

it('says nothing has read a table nothing has read, rather than zero percent', function (): void {
    expect(TableProfilePresenter::rows(quietTable())['sequential_scans'])->toBe('0 · — of reads');
});

it('shows the HOT update share on a table that is written to', function (): void {
    expect(TableProfilePresenter::rows(busyTable())['hot_updates'])->toBe('5,000 · 25.0%');
});

it('shows a dash for the HOT share of a table nothing updates', function (): void {
    expect(TableProfilePresenter::rows(quietTable())['hot_updates'])->toBe('0 · —');
});

it('tells you when it was last vacuumed in words', function (): void {
    expect(TableProfilePresenter::rows(busyTable())['last_vacuum'])->toBe('1 day ago');
});

it('says never for a table that has never been vacuumed', function (): void {
    expect(TableProfilePresenter::rows(quietTable())['last_vacuum'])->toBe('never');
});

it('spells out the count at which autovacuum will start', function (): void {
    // threshold 50 + 0.2 * 1,000,000 live rows.
    expect(TableProfilePresenter::rows(busyTable())['vacuums_at'])->toBe('200,050 dead rows');
});

it('says whether autovacuum is tuned for this table or left at the defaults', function (): void {
    expect(TableProfilePresenter::rows(busyTable())['autovacuum'])->toBe('tuned for this table')
        ->and(TableProfilePresenter::rows(quietTable())['autovacuum'])->toBe('the server defaults');
});
