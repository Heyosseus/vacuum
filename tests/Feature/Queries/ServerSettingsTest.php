<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Queries\ServerSettings;
use Heyosseus\Vacuum\Values\Settings;

it('reads the settings the audit asks about, with the metadata that makes them actionable', function (): void {
    $settings = app(ServerSettings::class)->read();

    expect($settings)->toBeInstanceOf(Settings::class);

    $sharedBuffers = $settings->get('shared_buffers');

    expect($sharedBuffers)->not->toBeNull()
        ->and($sharedBuffers->name)->toBe('shared_buffers')
        ->and($sharedBuffers->context)->toBe('postmaster')
        ->and($sharedBuffers->changeRequires())->toBe('restart');
});

it('reads a setting whose context makes it changeable in a session', function (): void {
    $settings = app(ServerSettings::class)->read();

    expect($settings->get('statement_timeout')?->changeRequires())->toBe('session');
});

it('reports the server\'s statement_timeout and not the one Vacuum set on itself', function (): void {
    // The bug this closes: every query in the package runs through
    // ReadOnlyExecutor, which issues SET LOCAL statement_timeout before the
    // statement, and the audit then selected pg_settings.setting inside that same
    // transaction. It never read the server's configuration -- it read Vacuum's
    // own instrumentation and reported it as a fact about the database.
    $settings = app(ServerSettings::class)->read();
    $statementTimeout = $settings->get('statement_timeout');

    expect($statementTimeout)->not->toBeNull();

    // What the session sees is ReadOnlyExecutor's own 5000ms, which is exactly
    // the value that used to be handed to the configuration rules as though the
    // DBA had chosen it.
    expect($settings->runtimeValue('statement_timeout'))->toBe('5000')
        ->and($settings->value('statement_timeout'))->toBe($statementTimeout->resetValue);

    // And this test database, like a stock PostgreSQL, sets no statement_timeout
    // at all -- which is what the audit must see, whatever Vacuum did to its own
    // connection on the way in.
    expect($settings->value('statement_timeout'))->toBe('0');
});

it('answers isDefault from the configured value rather than the session one', function (): void {
    // Same failure in a quieter place: comparing a SET LOCAL value against
    // boot_val says somebody made a decision about statement_timeout when the
    // only thing that touched it was this package.
    expect(app(ServerSettings::class)->read()->get('statement_timeout')?->isDefault())->toBeTrue();
});
