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
