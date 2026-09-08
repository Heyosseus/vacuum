<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Rules\JsonNotJsonb;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Values\Column;
use Heyosseus\Vacuum\Values\TableSchema;

function events(string ...$types): TableSchema
{
    $columns = [];

    foreach ($types as $index => $type) {
        $columns[] = new Column(
            schema: 'public', table: 'events', name: 'payload'.$index, type: $type, nullable: true,
        );
    }

    return new TableSchema('public', 'events', $columns, [], []);
}

it('reports a json column', function (): void {
    $findings = app(JsonNotJsonb::class)->inspect(events('json'));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('json-not-jsonb')
        ->and($findings[0]->subject)->toBe('public.events.payload0');
});

it('is information rather than a fault', function (): void {
    // A json column is a defensible choice often enough that failing a build on
    // it would be presumptuous. Info costs the score nothing.
    expect(app(JsonNotJsonb::class)->inspect(events('json'))[0]->severity)->toBe(Severity::Info);
});

it('says nothing about jsonb', function (): void {
    expect(app(JsonNotJsonb::class)->inspect(events('jsonb')))->toBe([]);
});

it('says nothing about a text column that merely holds json', function (): void {
    expect(app(JsonNotJsonb::class)->inspect(events('text')))->toBe([]);
});

it('explains what json cannot do that jsonb can', function (): void {
    $findings = app(JsonNotJsonb::class)->inspect(events('json'));

    expect($findings[0]->impact)->toContain('GIN')
        ->and($findings[0]->impact)->toContain('reparse');
});

it('offers the type change', function (): void {
    expect(app(JsonNotJsonb::class)->inspect(events('json'))[0]->remediation)
        ->toBe('ALTER TABLE "public"."events" ALTER COLUMN "payload0" TYPE jsonb USING "payload0"::jsonb;');
});
