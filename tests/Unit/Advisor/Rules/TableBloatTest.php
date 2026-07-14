<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\TableBloat;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\BloatEstimate;

function estimate(int $realBytes, int $bloatBytes): BloatEstimate
{
    return new BloatEstimate(
        schema: 'public',
        name: 'crates',
        fillfactor: 100,
        realBytes: $realBytes,
        bloatBytes: $bloatBytes,
    );
}

it('says nothing about a table wasting less than the threshold', function (): void {
    config()->set('vacuum.thresholds.bloat_bytes', 100 * 1024 * 1024);

    // A perfectly packed table still estimates a rounded-up page of waste, so a
    // rule that fired on any bloat at all would fire on every table you own.
    expect(app(TableBloat::class)->inspect(estimate(realBytes: 1_000_000, bloatBytes: 8_192)))->toBeNull();
});

it('reports a table wasting more space than the threshold allows', function (): void {
    config()->set('vacuum.thresholds.bloat_bytes', 10 * 1024 * 1024);

    $finding = app(TableBloat::class)->inspect(estimate(realBytes: 100 * 1024 * 1024, bloatBytes: 20 * 1024 * 1024));

    expect($finding?->rule)->toBe('table-bloat')
        ->and($finding?->subject)->toBe('public.crates')
        ->and($finding?->severity)->toBe(Severity::Warning)
        ->and($finding?->summary)->toContain('20.0 MB');
});

it('raises its voice when most of the table is waste', function (): void {
    config()->set('vacuum.thresholds.bloat_bytes', 10 * 1024 * 1024);

    $finding = app(TableBloat::class)->inspect(estimate(realBytes: 100 * 1024 * 1024, bloatBytes: 60 * 1024 * 1024));

    expect($finding?->severity)->toBe(Severity::Critical);
});

it('offers the rewrite that would reclaim the space, and says what it costs', function (): void {
    config()->set('vacuum.thresholds.bloat_bytes', 10 * 1024 * 1024);

    $finding = app(TableBloat::class)->inspect(estimate(realBytes: 100 * 1024 * 1024, bloatBytes: 20 * 1024 * 1024));

    // VACUUM FULL is not advice you give somebody casually: it rewrites the table
    // and holds a lock that stops even reads for the whole rewrite.
    expect($finding?->remediation)->toBe('VACUUM FULL "public"."crates";')
        ->and($finding?->impact)->toContain('ACCESS EXCLUSIVE')
        ->and($finding?->impact)->toContain('pg_repack');
});
