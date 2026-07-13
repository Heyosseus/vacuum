<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Database\ConnectionResolver;
use Heyosseus\Vacuum\Exceptions\UnsupportedDriver;

it('resolves the application default connection when none is configured', function (): void {
    expect(config('vacuum.connection'))->toBeNull();

    $connection = app(ConnectionResolver::class)->resolve();

    expect($connection->getName())->toBe('pgsql')
        ->and($connection->getDriverName())->toBe('pgsql');
});

it('resolves the connection named in the configuration', function (): void {
    config()->set('database.connections.analytics', config('database.connections.pgsql'));
    config()->set('vacuum.connection', 'analytics');

    $connection = app(ConnectionResolver::class)->resolve();

    expect($connection->getName())->toBe('analytics');
});

it('refuses to inspect a connection that is not postgresql', function (): void {
    config()->set('database.connections.legacy', ['driver' => 'sqlite', 'database' => ':memory:']);
    config()->set('vacuum.connection', 'legacy');

    app(ConnectionResolver::class)->resolve();
})->throws(UnsupportedDriver::class, 'Vacuum inspects PostgreSQL only, but the [legacy] connection uses the [sqlite] driver.');
