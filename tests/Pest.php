<?php

use Goldnead\Events\Tests\TestCase;

/*
 * Loaded before any test runs, so `class_exists()` gives the whole run the same
 * answer about the optional sibling addon. A fixture that declared itself lazily
 * inside one test would make every other test's result depend on file order.
 * See the fixture's own header for what it stands in for and why.
 */
require_once __DIR__.'/Fixtures/StandInActivityFacade.php';

uses(TestCase::class)->in('Feature', 'Unit');
