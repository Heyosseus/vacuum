<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Tests\ConsoleTestCase;
use Heyosseus\Vacuum\Tests\DisabledTestCase;
use Heyosseus\Vacuum\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/*
 * The master switch and the console switch are both read while routes are being
 * registered, before any test body runs, so the tests that prove they work have to
 * boot a differently configured application rather than flip a config value.
 */
uses(DisabledTestCase::class)->in('Disabled');
uses(ConsoleTestCase::class)->in('Console');
