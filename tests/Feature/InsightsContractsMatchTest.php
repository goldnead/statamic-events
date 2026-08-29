<?php

/*
 * The guard over the copies.
 *
 * `tests/Fakes/insights-contracts.php` claims to be the analytics addon's
 * contract copied byte for byte, and `tests/Fakes/insights-table-metric.php`
 * claims to be its base class copied the same way. Until this file existed,
 * nothing checked either claim. The sibling is neither a `require` nor a
 * `require-dev`, so the `interface_exists` locks in the stand-ins never engage:
 * the copies are what the whole suite runs against **and** what PHPStan
 * analyses through `scanFiles`. A method added to `Metric` upstream would
 * therefore leave every test here green and fatal on the first install that has
 * both addons — a green suite over a contract that no longer exists is worse
 * than no suite, because it is believed.
 *
 * So the copies are held against the originals wherever the originals can be
 * found: installed in `vendor/`, or checked out beside this package, which is
 * how the family is developed. Where they cannot be found the tests skip and
 * say why — a machine without the sibling cannot answer the question, and
 * pretending otherwise is the failure this file exists to prevent.
 *
 * The interface comparison runs through a second PHP process
 * (`tests/Support/insights-contract-probe.php`) because both sides declare the
 * same fully qualified names and one process can hold only one of them.
 *
 * The contract is semver-locked from the release onwards. This is the thing
 * that notices when it moves anyway.
 */

/** The interfaces a metric here implements or is read through. */
const INSIGHTS_CONTRACTS = ['Contracts\Metric', 'Contracts\HasBreakdowns', 'Contracts\HasFilterOptions'];

/** The value objects the queries read. */
const INSIGHTS_VALUE_OBJECTS = ['Support\MetricQuery', 'Support\Period', 'Support\Unit'];

/**
 * Constants whose **value** this package depends on, not just their name.
 *
 * `bucketExpression()` compares against `BUCKET_MONTH`, and `unit()` returns
 * these strings straight to a screen that formats by them. A renamed value
 * upstream would silently turn every monthly chart daily.
 */
const INSIGHTS_LOAD_BEARING_CONSTANTS = [
    'Support\MetricQuery' => ['BUCKET_DAY', 'BUCKET_MONTH'],
    'Support\Unit' => ['COUNT', 'CURRENCY', 'PERCENT', 'DURATION'],
];

/** Where the real package is, if it is anywhere. */
function insightsSiblingSource(): ?string
{
    $root = dirname(__DIR__, 2);

    $candidates = [
        $root.'/vendor/goldnead/statamic-insights/src',
        dirname($root).'/statamic-insights/src',
    ];

    foreach ($candidates as $candidate) {
        if (is_dir($candidate.'/Contracts')) {
            return $candidate;
        }
    }

    return null;
}

/**
 * The shape of a contract, read in a process of its own.
 *
 * @param  array<int, string>  $files
 * @return array<string, ?array<string, mixed>>
 */
function insightsShapeOf(array $files): array
{
    $probe = dirname(__DIR__).'/Support/insights-contract-probe.php';

    $command = implode(' ', array_map(
        'escapeshellarg',
        array_merge([PHP_BINARY, $probe], $files),
    ));

    $output = shell_exec($command.' 2>&1');
    $read = json_decode((string) $output, true);

    expect($read)->toBeArray();

    return $read;
}

/**
 * An addition upstream breaks the copy just as a removal does.
 *
 * A metric written against a stand-in that is missing a method does not
 * implement the real interface at all, so equality is the right test here and a
 * subset would not be: both directions are fatal.
 */
it('still matches the real contract', function () {
    $source = insightsSiblingSource();

    if ($source === null) {
        $this->markTestSkipped(
            'goldnead/statamic-insights was not found — neither in vendor/ nor checked out beside this package. '
            .'It is a `suggest` and deliberately not installed, so this machine cannot say whether '
            .'tests/Fakes/insights-contracts.php still matches the real contract. Run this where the sibling exists.'
        );
    }

    $files = glob($source.'/Contracts/*.php') ?: [];

    foreach (['MetricQuery', 'Period', 'Unit'] as $valueObject) {
        $files[] = $source.'/Support/'.$valueObject.'.php';
    }

    $real = insightsShapeOf($files);
    $copy = insightsShapeOf([__DIR__.'/../Fakes/insights-contracts.php']);

    foreach (INSIGHTS_CONTRACTS as $name) {
        $this->assertNotNull($real[$name], "The sibling no longer declares {$name}.");
        $this->assertNotNull($copy[$name], "The stand-in does not declare {$name}.");

        $this->assertSame(
            $real[$name]['methods'],
            $copy[$name]['methods'],
            "The stand-in for {$name} has drifted from the real contract. Copy it across again — every metric in src/Integrations/Insights is written against this shape, and the whole suite runs on the copy.",
        );
    }
});

/**
 * The value objects, as a subset rather than an equality.
 *
 * Deliberately looser than the interfaces above, and for a reason that does not
 * apply to them: a field added to `MetricQuery` upstream breaks nothing here —
 * the queries read what they read. Demanding equality would paint this test red
 * on a purely additive release and teach whoever sees it to ignore the file.
 * What must hold is that everything the copy promises is really there and
 * really has that shape.
 */
it('still carries what the metrics read', function () {
    $source = insightsSiblingSource();

    if ($source === null) {
        $this->markTestSkipped('goldnead/statamic-insights was not found; this machine cannot compare the value objects.');
    }

    $files = [];

    foreach (['MetricQuery', 'Period', 'Unit'] as $valueObject) {
        $files[] = $source.'/Support/'.$valueObject.'.php';
    }

    $real = insightsShapeOf(array_merge(glob($source.'/Contracts/*.php') ?: [], $files));
    $copy = insightsShapeOf([__DIR__.'/../Fakes/insights-contracts.php']);

    foreach (INSIGHTS_VALUE_OBJECTS as $name) {
        $this->assertNotNull($real[$name], "The sibling no longer declares {$name}.");
        $this->assertNotNull($copy[$name], "The stand-in does not declare {$name}.");

        foreach ($copy[$name]['methods'] as $method => $shape) {
            $this->assertArrayHasKey($method, $real[$name]['methods'], "{$name}::{$method}() exists only in the stand-in.");
            $this->assertSame($real[$name]['methods'][$method], $shape, "{$name}::{$method}() has a different signature upstream.");
        }

        foreach ($copy[$name]['properties'] as $property => $shape) {
            $this->assertArrayHasKey($property, $real[$name]['properties'], "{$name}::\${$property} exists only in the stand-in.");
            $this->assertSame($real[$name]['properties'][$property], $shape, "{$name}::\${$property} is declared differently upstream.");
        }
    }

    foreach (INSIGHTS_LOAD_BEARING_CONSTANTS as $name => $constants) {
        foreach ($constants as $constant) {
            $this->assertArrayHasKey($constant, $real[$name]['constants'], "{$name}::{$constant} is gone upstream.");
            $this->assertSame(
                $real[$name]['constants'][$constant],
                $copy[$name]['constants'][$constant] ?? null,
                "{$name}::{$constant} means something else upstream, and this package reads its value.",
            );
        }
    }
});

/**
 * The base class, byte for byte — a stricter standard than the interfaces get,
 * and deliberately so.
 *
 * `TableMetric` is not a contract but an implementation the three metrics in
 * this package inherit. A signature probe would prove almost nothing about it:
 * its public surface is three small methods, while everything the metrics
 * actually run on — `inPeriod`, `bucketExpression`, `bucketed`,
 * `splitByColumn`, `labelled` — is protected.
 *
 * Worse, a base class can change its **behaviour** without changing a single
 * signature. A corrected bucket expression, a different treatment of the empty
 * string in a split, a `where` that becomes inclusive: each of those would sail
 * through an equality check on method shapes, and each of them changes every
 * number this addon reports. In production the metrics inherit the sibling's
 * real file; in this suite they inherit the copy. If the two drift, the suite is
 * green over code that is not the code that ships, which is the precise failure
 * the file next door exists to prevent for interfaces.
 *
 * Byte equality is the only check that closes that gap, and it costs nothing to
 * satisfy: the copy is made with `cp`, so when it moves, `cp` it again.
 */
it('keeps the base class the metrics extend byte for byte', function () {
    $source = insightsSiblingSource();

    if ($source === null) {
        $this->markTestSkipped(
            'goldnead/statamic-insights was not found — neither in vendor/ nor checked out beside this package. '
            .'tests/Fakes/insights-table-metric.php is a verbatim copy of its Support/TableMetric.php and cannot be '
            .'compared here. Run this where the sibling exists.'
        );
    }

    $real = $source.'/Support/TableMetric.php';
    $copy = __DIR__.'/../Fakes/insights-table-metric.php';

    expect($real)->toBeReadableFile()
        ->and($copy)->toBeReadableFile();

    $this->assertSame(
        file_get_contents($real),
        file_get_contents($copy),
        'tests/Fakes/insights-table-metric.php is no longer the file the metrics inherit in production. '
        .'Copy it across again: cp '.$real.' '.$copy
    );
});
