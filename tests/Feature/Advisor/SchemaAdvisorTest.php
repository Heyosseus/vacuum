<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Advisor;
use Heyosseus\Vacuum\Advisor\SchemaAdvisor;

it('resolves from the container with the schema tier attached', function (): void {
    expect(app(SchemaAdvisor::class))->toBeInstanceOf(SchemaAdvisor::class);
});

it('does not put schema findings into the main advisor', function (): void {
    // The whole point of the separation: nothing a schema rule finds may change
    // the score an existing installation has been reading.
    foreach (app(Advisor::class)->findings() as $finding) {
        expect($finding->rule)->not->toBe('unindexed-foreign-key');
    }
});

it('carries duplicate-index into the schema tier as well', function (): void {
    // Two migrations creating the same index is visible on an empty database and
    // is exactly what the linter is for, so the one inspection serves both
    // advisors rather than being reimplemented for this one.
    DB::statement('DROP TABLE IF EXISTS lint_dupes CASCADE');
    DB::statement('CREATE TABLE lint_dupes (id bigserial PRIMARY KEY, label text)');
    DB::statement('CREATE INDEX lint_dupes_label_a ON lint_dupes (label)');
    DB::statement('CREATE INDEX lint_dupes_label_b ON lint_dupes (label)');

    $rules = array_map(
        static fn (object $finding): string => $finding->rule,
        app(SchemaAdvisor::class)->findings(),
    );

    expect($rules)->toContain('duplicate-index');

    DB::statement('DROP TABLE IF EXISTS lint_dupes CASCADE');
});

it('does not carry unused-index into the schema tier', function (): void {
    // It reads scan counts, and a database the pipeline created ninety seconds
    // ago has none — so every index in the schema would be reported as unused.
    $rules = array_map(
        static fn (object $finding): string => $finding->rule,
        app(SchemaAdvisor::class)->findings(),
    );

    expect($rules)->not->toContain('unused-index');
});
