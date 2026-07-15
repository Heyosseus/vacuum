<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Tests;

use Heyosseus\Vacuum\Vacuum;
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
        // The dashboard runs behind the 'web' middleware group, which encrypts
        // cookies, which needs a key. Nothing here is a secret.
        // AES-256 wants exactly 32 bytes.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_pad('vacuum-testing', 32, '-key')));

        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', $this->connection());

        // A second connection to the same database, so that a test can hold a
        // session open and then look at it. A transaction sitting idle cannot be
        // observed down the connection that is holding it.
        $app['config']->set('database.connections.pgsql_bystander', $this->connection());
    }

    /**
     * @return array<string, string>
     */
    private function connection(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => $this->fromEnvironment('DB_HOST', '127.0.0.1'),
            'port' => $this->fromEnvironment('DB_PORT', '5432'),
            'database' => $this->fromEnvironment('DB_DATABASE', 'vacuum_test'),
            'username' => $this->fromEnvironment('DB_USERNAME', 'vacuum'),
            'password' => $this->fromEnvironment('DB_PASSWORD', 'secret'),
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    /**
     * The authorization callback is static, so it outlives the application the
     * test booted it into. Left behind, it would authorize the next test.
     *
     * Connections are purged for the same reason: each test's PDO session
     * otherwise stays open until the whole run exits, and a few hundred tests
     * exhaust PostgreSQL's hundred-connection default mid-suite.
     */
    protected function tearDown(): void
    {
        Vacuum::auth(null);

        if ($this->app !== null) {
            $manager = $this->app->make('db');

            foreach (array_keys($manager->getConnections()) as $name) {
                $manager->purge($name);
            }
        }

        parent::tearDown();
    }

    private function fromEnvironment(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
