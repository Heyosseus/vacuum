<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Learn\Branch;
use Heyosseus\Vacuum\Learn\Tree;

it('reports a branch as taken only when the reader own tables landed on it', function (): void {
    $taken = new Branch(condition: 'fillfactor is still 100', outcome: 'lower it', landed: ['public.sessions']);
    $untaken = new Branch(condition: 'an indexed column changed', outcome: 'drop the index');

    expect($taken->isTaken())->toBeTrue()
        ->and($untaken->isTaken())->toBeFalse();
});

it('keeps every branch whether or not this database demonstrates it', function (): void {
    $tree = new Tree('Is a low HOT share worth fixing?', [
        new Branch(condition: 'fillfactor is still 100', outcome: 'lower it', landed: ['public.sessions']),
        new Branch(condition: 'an indexed column changed', outcome: 'drop the index'),
    ]);

    expect($tree->branches)->toHaveCount(2)
        ->and($tree->question)->toBe('Is a low HOT share worth fixing?');
});

it('carries an optional statement for the branch that has one', function (): void {
    $branch = new Branch(
        condition: 'fillfactor is still 100',
        outcome: 'lower it',
        landed: ['public.sessions'],
        fix: 'alter table sessions set (fillfactor = 85);',
    );

    expect($branch->fix)->toBe('alter table sessions set (fillfactor = 85);');
});
