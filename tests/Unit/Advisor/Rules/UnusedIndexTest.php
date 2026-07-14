<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heyosseus\Vacuum\Advisor\Rules\UnusedIndex;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\IndexStatistic;

function anIndex(
    int $scans = 0,
    int $bytes = 50 * 1024 * 1024,
    bool $unique = false,
    bool $primary = false,
    ?CarbonImmutable $countingSince = null,
): IndexStatistic {
    return new IndexStatistic(
        schema: 'public',
        table: 'pallets',
        name: 'pallets_label_index',
        scans: $scans,
        bytes: $bytes,
        unique: $unique,
        primary: $primary,
        countingSince: $countingSince,
    );
}

beforeEach(function (): void {
    config()->set('vacuum.thresholds.unused_index_min_size', 1024 * 1024);
});

it('says nothing about an index queries are using', function (): void {
    expect(app(UnusedIndex::class)->inspect(anIndex(scans: 12)))->toBeNull();
});

it('says nothing about an index too small to be worth the trouble', function (): void {
    expect(app(UnusedIndex::class)->inspect(anIndex(bytes: 8_192)))->toBeNull();
});

it('leaves a constraint alone however unused it looks', function (): void {
    // A unique index is not an optimisation, it is a rule the database enforces.
    // Dropping it changes what the application is allowed to store.
    expect(app(UnusedIndex::class)->inspect(anIndex(unique: true)))->toBeNull()
        ->and(app(UnusedIndex::class)->inspect(anIndex(primary: true)))->toBeNull();
});

it('reports a large index nothing has ever read', function (): void {
    $finding = app(UnusedIndex::class)->inspect(anIndex());

    expect($finding?->rule)->toBe('unused-index')
        ->and($finding?->subject)->toBe('public.pallets_label_index')
        ->and($finding?->severity)->toBe(Severity::Warning)
        ->and($finding?->summary)->toContain('50.0 MB')
        ->and($finding?->remediation)->toBe('DROP INDEX CONCURRENTLY "public"."pallets_label_index";');
});

it('says how long nothing has been reading it', function (): void {
    $finding = app(UnusedIndex::class)->inspect(
        anIndex(countingSince: CarbonImmutable::parse('2026-01-01 09:00:00')),
    );

    expect($finding?->summary)->toContain('1 Jan 2026');
});

it('admits it does not know how long, when postgres never said', function (): void {
    expect(app(UnusedIndex::class)->inspect(anIndex())?->summary)
        ->toContain('as long as PostgreSQL has been counting');
});

it('warns that an index unused here may be busy on a replica', function (): void {
    // The counters are per server. An index untouched on the primary can be the
    // one holding up every read on a replica, and dropping it takes both down.
    expect(app(UnusedIndex::class)->inspect(anIndex())?->impact)->toContain('replica');
});
