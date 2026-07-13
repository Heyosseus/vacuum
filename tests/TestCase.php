<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Tests;

use Heyosseus\Vacuum\VacuumServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * The package providers Testbench boots for every test.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            VacuumServiceProvider::class,
        ];
    }

    /**
     * Tests run against a real PostgreSQL instance: Vacuum's whole purpose is
     * reading pg_stat_* and pg_class, which SQLite cannot fake.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => $this->fromEnvironment('DB_HOST', '127.0.0.1'),
            'port' => $this->fromEnvironment('DB_PORT', '5432'),
            'database' => $this->fromEnvironment('DB_DATABASE', 'vacuum_test'),
            'username' => $this->fromEnvironment('DB_USERNAME', 'vacuum'),
            'password' => $this->fromEnvironment('DB_PASSWORD', 'secret'),
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    private function fromEnvironment(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
