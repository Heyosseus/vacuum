<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Tests;

use Override;

/**
 * A package application with the Learn section switched off.
 *
 * Learn is on by default -- a package that teaches only when configured to
 * teaches nobody -- so proving the switch works is the inverse of what
 * {@see InternalsTestCase} does, and needs the same trick: the flag is read
 * while routes are being registered, long before a test body could set it.
 */
abstract class LearnDisabledTestCase extends TestCase
{
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vacuum.learn.enabled', false);
    }
}
