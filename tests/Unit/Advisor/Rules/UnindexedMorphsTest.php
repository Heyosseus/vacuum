<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\UnindexedMorphs;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Column;
use Heyosseus\Vacuum\Values\IndexDefinition;
use Heyosseus\Vacuum\Values\TableSchema;

function morphColumn(string $name, string $type): Column
{
    return new Column(schema: 'public', table: 'comments', name: $name, type: $type, nullable: false);
}

function comments(array $columns, array $indexes = []): TableSchema
{
    return new TableSchema('public', 'comments', $columns, [], $indexes);
}

function pairIndex(array $columns): IndexDefinition
{
    return new IndexDefinition(
        schema: 'public', table: 'comments', name: 'comments_idx', columns: $columns,
        unique: false, valid: true, partial: false, method: 'btree',
    );
}

$pair = [
    morphColumn('commentable_type', 'character varying(255)'),
    morphColumn('commentable_id', 'bigint'),
];

it('reports a morphs pair with no index', function () use ($pair): void {
    $findings = app(UnindexedMorphs::class)->inspect(comments($pair));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('unindexed-morphs')
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->subject)->toBe('public.comments.commentable_type');
});

it('says nothing when the composite index exists', function () use ($pair): void {
    $indexed = comments($pair, [pairIndex(['commentable_type', 'commentable_id'])]);

    expect(app(UnindexedMorphs::class)->inspect($indexed))->toBe([]);
});

it('still reports when the index leads with the id instead of the type', function () use ($pair): void {
    // Laravel queries a morph by type and id together, and morphs() creates the
    // index in that order. An index leading with the id serves a different
    // question from the one the relation asks.
    $wrong = comments($pair, [pairIndex(['commentable_id', 'commentable_type'])]);

    expect(app(UnindexedMorphs::class)->inspect($wrong))->toHaveCount(1);
});

it('ignores a type column with no matching id column', function (): void {
    expect(app(UnindexedMorphs::class)->inspect(comments([morphColumn('commentable_type', 'text')])))->toBe([]);
});

it('ignores an id column whose partner is not a string', function (): void {
    $notMorphs = [
        morphColumn('commentable_type', 'integer'),
        morphColumn('commentable_id', 'bigint'),
    ];

    expect(app(UnindexedMorphs::class)->inspect(comments($notMorphs)))->toBe([]);
});

it('ignores an id column that is not an integer', function (): void {
    // The type column has a matching *_id, but nothing here says it is the
    // integer half of a morphs() pair, so the rule declines to guess.
    $notBigint = [
        morphColumn('commentable_type', 'character varying(255)'),
        morphColumn('commentable_id', 'uuid'),
    ];

    expect(app(UnindexedMorphs::class)->inspect(comments($notBigint)))->toBe([]);
});

it('offers the composite index in the order the relation queries it', function () use ($pair): void {
    $findings = app(UnindexedMorphs::class)->inspect(comments($pair));

    expect($findings[0]->remediation)
        ->toBe('CREATE INDEX CONCURRENTLY ON "public"."comments" ("commentable_type", "commentable_id");');
});

it('reports two morphs pairs on one table separately', function (): void {
    $two = [
        morphColumn('commentable_type', 'character varying(255)'),
        morphColumn('commentable_id', 'bigint'),
        morphColumn('authorable_type', 'character varying(255)'),
        morphColumn('authorable_id', 'bigint'),
    ];

    expect(app(UnindexedMorphs::class)->inspect(comments($two)))->toHaveCount(2);
});
