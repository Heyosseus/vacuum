<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Tests;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Application;
use Override;

/**
 * A package application with history switched on and its tables in place.
 *
 * History is off by default, and its schema is published rather than loaded, so the
 * tables do not exist until an application opts in. These tests opt in for it: the
 * switch is set before the app boots — the Blade history route is registered off it —
 * and the migration is run and torn down around each test against the real database.
 */
abstract class HistoryTestCase extends TestCase
{
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Read while routes register, so it has to be set before boot rather than
        // flipped inside a test body.
        $app['config']->set('vacuum.history.enabled', true);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Down first, in case a previous run died before its teardown and left the
        // tables behind in the shared database.
        $this->migration()->down();
        $this->migration()->up();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->migration()->down();

        parent::tearDown();
    }

    /**
     * A fresh instance of the published migration. Required, not required_once, so a
     * second call in teardown re-executes the file and hands back a new object rather
     * than the boolean a repeat include returns.
     */
    private function migration(): Migration
    {
        return require __DIR__.'/../database/migrations/create_vacuum_history_tables.php';
    }
}
