<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Console\Support\Baseline;

function baselined(string $rule = 'unindexed-foreign-key', string $subject = 'public.orders.customer_id'): Finding
{
    return new Finding(
        rule: $rule,
        subject: $subject,
        severity: Severity::Warning,
        summary: 'stub',
        impact: 'stub',
        table: 'public.orders',
    );
}

it('suppresses nothing when there is no baseline', function (): void {
    expect(Baseline::none()->suppresses(baselined()))->toBeFalse();
});

it('suppresses a finding whose rule and subject both appear', function (): void {
    $baseline = Baseline::record([baselined()]);

    expect($baseline->suppresses(baselined()))->toBeTrue();
});

it('does not suppress the same subject under a different rule', function (): void {
    // Matching on the pair is the whole point: one table can be wrong in two
    // unrelated ways and silencing one must not silence the other.
    $baseline = Baseline::record([baselined()]);

    expect($baseline->suppresses(baselined(rule: 'json-not-jsonb')))->toBeFalse();
});

it('does not suppress a different subject under the same rule', function (): void {
    $baseline = Baseline::record([baselined()]);

    expect($baseline->suppresses(baselined(subject: 'public.orders.warehouse_id')))->toBeFalse();
});

it('ignores everything about a finding except its rule and subject', function (): void {
    // Rewording a rule's prose, or raising its severity in a later release, must
    // never invalidate a baseline somebody committed months ago.
    $baseline = Baseline::record([baselined()]);

    $reworded = new Finding(
        rule: 'unindexed-foreign-key',
        subject: 'public.orders.customer_id',
        severity: Severity::Critical,
        summary: 'completely different words',
        impact: 'completely different words',
        remediation: 'CREATE INDEX something;',
    );

    expect($baseline->suppresses($reworded))->toBeTrue();
});

it('encodes subjects sorted, so the file has no spurious diffs', function (): void {
    $baseline = Baseline::record([
        baselined(subject: 'public.orders.warehouse_id'),
        baselined(subject: 'public.orders.customer_id'),
    ]);

    $document = json_decode($baseline->encode('1.2.0'), true);

    expect($document['findings']['unindexed-foreign-key'])
        ->toBe(['public.orders.customer_id', 'public.orders.warehouse_id'])
        ->and($document['vacuum'])->toBe('1.2.0')
        ->and($document)->toHaveKey('generated_at');
});

it('round-trips through encode and decode', function (): void {
    $baseline = Baseline::record([baselined()]);

    expect(Baseline::decode($baseline->encode('1.2.0'))->suppresses(baselined()))->toBeTrue();
});

it('treats a malformed file as no baseline rather than crashing a build', function (): void {
    // A corrupt baseline should not take the pipeline down with it; reporting
    // everything is the safe direction to fail in.
    expect(Baseline::decode('not json at all')->suppresses(baselined()))->toBeFalse()
        ->and(Baseline::decode('{"findings": "wrong shape"}')->suppresses(baselined()))->toBeFalse()
        ->and(Baseline::decode('[]')->suppresses(baselined()))->toBeFalse()
        ->and(Baseline::decode('"just a string"')->suppresses(baselined()))->toBeFalse();
});

it('skips a malformed entry instead of discarding the whole baseline', function (): void {
    // json_decode(..., true) turns a numeric-looking JSON key into an int array
    // key, so a rule of "0" is indistinguishable from list noise; and a rule
    // whose subjects are not a list is just as unusable. Either is dropped on
    // its own, rather than a single bad entry invalidating every entry beside it.
    $baseline = Baseline::decode(
        '{"findings": {'
        .'"0": ["public.orders.customer_id"], '
        .'"json-not-jsonb": "public.orders.payload", '
        .'"unindexed-foreign-key": ["public.orders.warehouse_id"]'
        .'}}'
    );

    expect($baseline->suppresses(baselined(subject: 'public.orders.warehouse_id')))->toBeTrue()
        ->and($baseline->suppresses(baselined()))->toBeFalse()
        ->and($baseline->suppresses(baselined(rule: 'json-not-jsonb', subject: 'public.orders.payload')))->toBeFalse();
});

it('reports an entry that no longer matches anything', function (): void {
    // A baseline nobody prunes becomes a place defects hide.
    $baseline = Baseline::record([baselined(), baselined(subject: 'public.orders.gone')]);

    $stale = $baseline->stale([baselined()]);

    expect($stale)->toHaveCount(1)
        ->and($stale[0]->rule)->toBe('baseline-stale')
        ->and($stale[0]->severity)->toBe(Severity::Info)
        ->and($stale[0]->subject)->toBe('public.orders.gone')
        ->and($stale[0]->summary)->toContain('unindexed-foreign-key');
});

it('reports nothing stale when every entry still matches', function (): void {
    expect(Baseline::record([baselined()])->stale([baselined()]))->toBe([]);
});
