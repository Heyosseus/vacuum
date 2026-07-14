<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Exceptions\MissingStatement;
use Heyosseus\Vacuum\Support\SqlRepository;

it('reads a statement out of the package', function (): void {
    $sql = app(SqlRepository::class)->get('table_bloat');

    expect($sql)->toContain('pg_class')
        ->and($sql)->toContain('fillfactor');
});

it('reads a statement from disk once, however many queries ask', function (): void {
    $repository = app(SqlRepository::class);

    expect($repository->get('table_bloat'))->toBe($repository->get('table_bloat'));
});

it('refuses a name that could climb out of the statement directory', function (): void {
    // The names are the package's own constants today. They are checked anyway,
    // because the day one of them is built from a request is the day this matters.
    app(SqlRepository::class)->get('../../../../etc/passwd');
})->throws(MissingStatement::class);

it('says which statement it could not find', function (): void {
    app(SqlRepository::class)->get('no_such_statement');
})->throws(MissingStatement::class, 'no_such_statement');
