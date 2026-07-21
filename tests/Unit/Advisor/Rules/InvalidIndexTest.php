<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\InvalidIndex;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\IndexStatistic;

function halfBuilt(bool $valid, int $bytes = 40 * 1024 * 1024, string $name = 'pallets_label_index'): IndexStatistic
{
    return new IndexStatistic(
        schema: 'public',
        table: 'pallets',
        name: $name,
        scans: 0,
        bytes: $bytes,
        unique: false,
        primary: false,
        valid: $valid,
        constraintOwned: false,
        replicaIdentity: false,
        partitionChild: false,
        countingSince: null,
    );
}

it('says nothing about an index the planner can use', function (): void {
    expect(app(InvalidIndex::class)->inspect(halfBuilt(valid: true)))->toBeNull();
});

it('warns about an index postgresql will not use', function (): void {
    $finding = app(InvalidIndex::class)->inspect(halfBuilt(valid: false));

    expect($finding)->not->toBeNull()
        ->and($finding->rule)->toBe('invalid-index')
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe('public.pallets_label_index')
        ->and($finding->summary)->toContain('40.0 MB');
});

it('says the index costs writes while serving no reads', function (): void {
    $finding = app(InvalidIndex::class)->inspect(halfBuilt(valid: false));

    expect($finding->impact)->toContain('no query can use it')
        ->and($finding->impact)->toContain('every insert');
});

it('admits an index being built right now looks exactly like this', function (): void {
    // CREATE INDEX CONCURRENTLY marks the index invalid until it finishes. A tool
    // that told you to drop the index somebody is building would be worse than one
    // that said nothing.
    $finding = app(InvalidIndex::class)->inspect(halfBuilt(valid: false));

    expect($finding->impact)->toContain('CREATE INDEX CONCURRENTLY');
});

it('offers the drop and the rebuild, in that order', function (): void {
    $finding = app(InvalidIndex::class)->inspect(halfBuilt(valid: false, name: 'my "odd" index'));

    expect($finding->remediation)->toBe('DROP INDEX CONCURRENTLY "public"."my ""odd"" index";');
});
