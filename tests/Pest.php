<?php

use Goldnead\Events\Tests\TestCase;
use Goldnead\StatamicInsights\Support\TableMetric;

/*
 * Loaded before any test runs, so `class_exists()` gives the whole run the same
 * answer about the optional sibling addon. A fixture that declared itself lazily
 * inside one test would make every other test's result depend on file order.
 * See the fixture's own header for what it stands in for and why.
 */
require_once __DIR__.'/Fixtures/StandInActivityFacade.php';

/*
 * The same trade for the other optional sibling, statamic-insights, in the order
 * the declarations depend on each other: the contracts first, then the base
 * class the metrics extend — which implements one of them — and the facade the
 * ServiceProvider probes for last.
 *
 * The base class is loaded only when the real package is absent. It is a
 * verbatim copy of the sibling's own file rather than a rewrite, because the
 * metrics inherit their whole arithmetic from it and a paraphrase would mean the
 * suite tests different code than production runs. InsightsContractsMatchTest
 * holds all three copies against the originals.
 */
require_once __DIR__.'/Fakes/insights-contracts.php';

if (! class_exists(TableMetric::class)) {
    require_once __DIR__.'/Fakes/insights-table-metric.php';
}

require_once __DIR__.'/Fakes/insights-facade.php';

uses(TestCase::class)->in('Feature', 'Unit');
