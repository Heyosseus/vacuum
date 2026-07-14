<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\DuplicateIndex;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\IndexDuplicate;

function copied(bool $constrains = false, int $bytes = 30 * 1024 * 1024, string $name = 'pallets_label_idx'): IndexDuplicate
{
    return new IndexDuplicate(
        schema: 'public',
        table: 'pallets',
        name: $name,
        bytes: $bytes,
        definition: 'CREATE INDEX pallets_label_idx ON public.pallets USING btree (label)',
        duplicateOf: 'pallets_label_index',
        constrains: $constrains,
    );
}

it('names the index being duplicated, not just the duplicate', function (): void {
    // "This index is a duplicate" is not something anyone can act on without
    // "of that one".
    $finding = app(DuplicateIndex::class)->inspect(copied());

    expect($finding->rule)->toBe('duplicate-index')
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe('public.pallets_label_idx')
        ->and($finding->summary)->toContain('pallets_label_index')
        ->and($finding->summary)->toContain('30.0 MB');
});

it('shows the definition the two indexes share', function (): void {
    $finding = app(DuplicateIndex::class)->inspect(copied());

    expect($finding->evidence)->toContain('USING btree (label)');
});

it('says the cost is paid twice for one benefit', function (): void {
    $finding = app(DuplicateIndex::class)->inspect(copied());

    expect($finding->impact)->toContain('twice')
        ->and($finding->impact)->toContain('pallets');
});

it('offers the drop', function (): void {
    $finding = app(DuplicateIndex::class)->inspect(copied(name: 'my "odd" index'));

    expect($finding->remediation)->toBe('DROP INDEX CONCURRENTLY "public"."my ""odd"" index";');
});

it('warns that a constraint will refuse the drop', function (): void {
    // A unique index backing a constraint cannot be dropped as an index. PostgreSQL
    // will say so, and the finding should say so first.
    $finding = app(DuplicateIndex::class)->inspect(copied(constrains: true));

    expect($finding->impact)->toContain('constraint')
        ->and($finding->impact)->toContain('ALTER TABLE');
});

it('does not mention constraints for an index that enforces nothing', function (): void {
    expect(app(DuplicateIndex::class)->inspect(copied())->impact)->not->toContain('ALTER TABLE');
});
