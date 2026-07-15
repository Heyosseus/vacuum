<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Tests;

use Illuminate\Foundation\Application;
use Override;

/**
 * Boots the package with its UI set to Filament. Like the master switch, the UI
 * mode is read while routes are being registered, before any test body runs, so
 * it has to be configured into the environment rather than flipped from a test.
 */
abstract class FilamentUiTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vacuum.ui', 'filament');
    }
}
