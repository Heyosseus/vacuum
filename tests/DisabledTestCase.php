<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Tests;

use Illuminate\Foundation\Application;
use Override;

/**
 * Boots the package with the master switch off. The switch is read while routes
 * are being registered, which happens before any test body runs, so it cannot be
 * flipped from inside a test.
 */
abstract class DisabledTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vacuum.enabled', false);
    }
}
