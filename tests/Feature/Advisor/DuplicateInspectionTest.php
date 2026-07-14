<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Inspections\DuplicateInspection;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS pallets');
    DB::statement('CREATE TABLE pallets (id serial PRIMARY KEY, label text)');
});

afterEach(function (): void {
    DB::statement('DROP TABLE IF EXISTS pallets');
});

it('turns a real duplicate index into a finding', function (): void {
    DB::statement('CREATE INDEX pallets_label_index ON pallets (label)');
    DB::statement('CREATE INDEX pallets_label_again ON pallets (label)');

    $findings = collect(app(DuplicateInspection::class)->findings())
        ->filter(fn ($finding): bool => str_contains($finding->subject, 'pallets_label'));

    expect($findings)->toHaveCount(1)
        ->and($findings->first()->rule)->toBe('duplicate-index')
        ->and($findings->first()->remediation)->toContain('DROP INDEX CONCURRENTLY');
});

it('has nothing to say about a schema without a duplicate in it', function (): void {
    DB::statement('CREATE INDEX pallets_label_index ON pallets (label)');

    expect(app(DuplicateInspection::class)->findings())->toBe([]);
});
