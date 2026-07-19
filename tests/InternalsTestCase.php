<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Tests;

use Override;

/**
 * A package application with the internals explorers switched on.
 *
 * The master switch is read while routes are being registered, before any test
 * body runs -- the same reason {@see HistoryTestCase} exists -- so a test that
 * needs `/vacuum/internals` to actually be routable has to boot a differently
 * configured application rather than flip the config value from inside itself.
 */
abstract class InternalsTestCase extends TestCase
{
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vacuum.internals.enabled', true);
    }
}
