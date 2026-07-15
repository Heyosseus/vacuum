<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Tests\ConsoleTestCase;
use Heyosseus\Vacuum\Tests\DisabledTestCase;
use Heyosseus\Vacuum\Tests\FilamentTestCase;
use Heyosseus\Vacuum\Tests\FilamentUiTestCase;
use Heyosseus\Vacuum\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/*
 * The master switch, the console switch and the UI mode are all read while routes
 * are being registered, before any test body runs, so the tests that prove they
 * work have to boot a differently configured application rather than flip a config
 * value.
 */
uses(DisabledTestCase::class)->in('Disabled');
uses(ConsoleTestCase::class)->in('Console');
uses(FilamentUiTestCase::class)->in('FilamentUi');

/*
 * The smoke tests boot a real Filament panel with Vacuum's plugin on it, so they
 * live under their own case rather than the bare package one.
 */
uses(FilamentTestCase::class)->in('Filament');
