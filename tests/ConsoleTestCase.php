<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Tests;

use Illuminate\Foundation\Application;
use Override;

/**
 * Boots the package with the SQL console switched on, which it is not by default.
 * The switch is read while routes are registered, so it cannot be thrown from
 * inside a test.
 */
abstract class ConsoleTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vacuum.console.enabled', true);
    }
}
