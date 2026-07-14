<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Tests\DisabledTestCase;
use Heyosseus\Vacuum\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/*
 * The master switch is read while routes are registered, before any test body
 * runs, so the tests that prove it works have to boot a differently configured
 * application rather than flip a config value.
 */
uses(DisabledTestCase::class)->in('Disabled');
