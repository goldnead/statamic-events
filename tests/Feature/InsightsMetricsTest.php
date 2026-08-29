<?php

use Carbon\CarbonImmutable;
use Goldnead\Events\Enums\EventStatus;
use Goldnead\Events\Enums\OccurrenceStatus;
use Goldnead\Events\Integrations\Insights\Cancelled;
use Goldnead\Events\Integrations\Insights\Occurrences;
use Goldnead\Events\Integrations\Insights\Published;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Events\ServiceProvider;
use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Facades\Insights as InsightsStandIn;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The three numbers this addon offers the analytics addon.
 *
 * Every expectation below is worked out by hand from one small fixture,
 * following the rules the metrics write down — events on `published_at`, dates
 * on `starts_at`, cancellations on `cancelled_at` — so a query that drifted
 * shows up as an arithmetic disagreement rather than as a green suite over a
 * different report.
 *
 * Tested against a stand-in for the contract rather than the real package, for
 * the same reason ActivityBridgeTest stands in for the ledger: the sibling is
 * optional, and a test that needed it installed would be proving the opposite of
 * what this addon claims. See tests/Fakes/insights-contracts.php for why those
 * are required files and not autoload entries.
 *
 * Time is frozen and every instant is written in UTC. The buckets are asserted
 * as literal dates, and a suite that ran across midnight would otherwise fail
 * once a night for reasons that have nothing to do with the code.
 */

/** Collects what the service provider offers, and is stricter than the real registry. */
function insightsCollector(): object
{
    return new class
    {
        /** @var array<string, string> */
        public array $registered = [];

        /**
         * The genuine manager accepts a metric without a handle and works one
         * out by constructing it. Accepting that here would let the provider
         * drop the handle and still look correct, and the handle is the half
         * that ends up in saved dashboards and URLs.
         */
        public function registerMetric(string|Metric|Closure $metric, ?string $handle = null): void
        {
            if (! is_string($metric) || $handle === null) {
                throw new InvalidArgumentException('This addon registers metrics lazily: a class name and a handle.');
            }

            $this->registered[$handle] = $metric;
        }
    };
}

/** The ten days the fixture lives in, bucketed by day, bounded in UTC. */
function insightsWindow(array $filters = [], string $bucket = MetricQuery::BUCKET_DAY): MetricQuery
{
    return new MetricQuery(
        Period::between(
            Carbon::parse('2026-08-11 00:00:00', 'UTC'),
            Carbon::parse('2026-08-20 23:59:59', 'UTC'),
        ),
        $bucket,
        $filters,
    );
}

function insightsMoment(string $utc): CarbonImmutable
{
    return CarbonImmutable::parse($utc, 'UTC');
}

/** @return array<string, int|float> */
function insightsKeyed(array $rows): array
{
    $keyed = [];

    foreach ($rows as $row) {
        $keyed[$row['key'] ?? ''] = $row['value'];
    }

    return $keyed;
}

/**
 * Four published events, four dates in the window, one cancellation inside it.
 *
 * Small enough to add up in the head, and every awkward case is in it: a draft
 * that was never published, an event published before the window, an event
 * whose type is empty, a date outside the window, a date that is cancelled but
 * was cancelled *before* the window began, and one cancelled inside it.
 */
function insightsFixture(): void
{
    // -- Events, counted on published_at ------------------------------------

    foreach ([
        ['type' => 'workshop', 'published_at' => '2026-08-12 10:00:00'],
        ['type' => 'concert', 'published_at' => '2026-08-12 14:00:00'],
        ['type' => 'workshop', 'published_at' => '2026-08-15 09:00:00'],
        // No type at all. `Event::creating` fills a *missing* type, not an
        // empty one, so this is a row the ordinary model path can produce, and
        // it must appear in the split rather than vanish from it.
        ['type' => '', 'published_at' => '2026-08-16 08:00:00'],
        // Published long before the window.
        ['type' => 'concert', 'published_at' => '2026-07-01 08:00:00'],
    ] as $event) {
        Event::factory()->create([
            'type' => $event['type'],
            'status' => EventStatus::Published->value,
            'published_at' => insightsMoment($event['published_at']),
        ]);
    }

    // A draft: no published_at, and therefore in no window at all.
    Event::factory()->create(['type' => 'workshop', 'status' => EventStatus::Draft->value]);

    // -- Dates, counted on starts_at ----------------------------------------

    Occurrence::factory()->create(['starts_at' => insightsMoment('2026-08-13 18:00:00')]);
    Occurrence::factory()->create(['starts_at' => insightsMoment('2026-08-13 20:00:00')]);

    // Plays inside the window, called off inside it too.
    Occurrence::factory()->create([
        'starts_at' => insightsMoment('2026-08-17 19:00:00'),
        'status' => OccurrenceStatus::Cancelled->value,
        'cancelled_at' => insightsMoment('2026-08-14 09:00:00'),
    ]);

    // Would have played inside the window, but was called off before it began.
    // It is a date of this period and not a cancellation of this period, which
    // is the whole reason the two figures sit on different axes.
    Occurrence::factory()->create([
        'starts_at' => insightsMoment('2026-08-19 19:00:00'),
        'status' => OccurrenceStatus::Cancelled->value,
        'cancelled_at' => insightsMoment('2026-07-30 11:00:00'),
    ]);

    // Next month: outside the window on both counts.
    Occurrence::factory()->create(['starts_at' => insightsMoment('2026-09-05 19:00:00')]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'UTC'));

    InsightsStandIn::$root = insightsCollector();

    // The bed booted the addon before this ran, when no root was bound yet, so
    // the provider's first attempt found the facade and no manager behind it.
    // Forgetting and booting again is the same retry production gets from its
    // two later hooks, and the repeat is safe because bootAddon() is idempotent.
    //
    // Asked of the provider object rather than of the class: the flag lives on
    // the instance, so that a worker which keeps the class between requests
    // cannot tell a freshly built registry that it has already been filled.
    app()->getProvider(ServiceProvider::class)->forgetInsightsMetrics();
    app()->getProvider(ServiceProvider::class)->bootAddon();
});

afterEach(function () {
    Carbon::setTestNow();
    InsightsStandIn::$root = null;
});

// -- The three figures --------------------------------------------------------

/**
 * All three at once, against hand-worked totals.
 *
 * One test rather than three, deliberately: they are read side by side on a
 * screen and have to agree with each other. Three separate tests are three
 * chances to fix one of them and leave the rest.
 */
it('counts what the calendar actually holds', function () {
    insightsFixture();

    $window = insightsWindow();

    expect((new Published)->value($window))->toBe(4)
        ->and((new Occurrences)->value($window))->toBe(4)
        ->and((new Cancelled)->value($window))->toBe(1);
});

/**
 * The two dated figures do not add up to each other, and that is the point.
 *
 * Two of the four dates in the window are cancelled, but only one of them was
 * cancelled *during* the window. A reader dividing the cancellation count by the
 * date count would get 25 % for a period in which half the dates are off, which
 * is why the metrics offer the status split instead of a rate.
 */
it('counts a cancellation on the day it went out, not on the day of the date', function () {
    insightsFixture();

    $window = insightsWindow();

    expect((new Cancelled)->value($window))->toBe(1)
        ->and(insightsKeyed((new Occurrences)->breakdown($window, 'status')))
        ->toBe([OccurrenceStatus::Scheduled->value => 2, OccurrenceStatus::Cancelled->value => 2]);
});

/**
 * Only the buckets that have something in them.
 *
 * The empty days are Insights' job — it fills the range for every metric at
 * once. A metric that filled its own would be filled twice, and one that
 * invented a bucket outside the range would draw a column the axis has no place
 * for.
 */
it('returns only the buckets that have data', function () {
    insightsFixture();

    $window = insightsWindow();

    expect((new Published)->series($window))->toBe([
        '2026-08-12' => 2,
        '2026-08-15' => 1,
        '2026-08-16' => 1,
    ])
        ->and((new Occurrences)->series($window))->toBe([
            '2026-08-13' => 2,
            '2026-08-17' => 1,
            '2026-08-19' => 1,
        ])
        // On the day the cancellation went out, not on the day of the date.
        ->and((new Cancelled)->series($window))->toBe(['2026-08-14' => 1]);
});

/**
 * The grain comes from the question, not from the period.
 *
 * Insights decides the grain and puts it in the query; a metric that worked it
 * out again from the period length could disagree with the axis it is drawn on.
 */
it('buckets by month when the question asks for months', function () {
    insightsFixture();

    $window = insightsWindow([], MetricQuery::BUCKET_MONTH);

    expect((new Published)->series($window))->toBe(['2026-08' => 4])
        ->and((new Occurrences)->series($window))->toBe(['2026-08' => 4]);
});

// -- The splits ---------------------------------------------------------------

/**
 * An event without a type is a row keyed null, not a missing row.
 *
 * A report that quietly excludes rows is the hardest kind of wrong to notice:
 * the columns still add up among themselves, and only the total disagrees, which
 * is the number nobody re-adds.
 */
it('splits by type and keeps the row that has none', function () {
    insightsFixture();

    $rows = (new Published)->breakdown(insightsWindow(), 'type');

    expect(insightsKeyed($rows))->toBe(['workshop' => 2, 'concert' => 1, '' => 1])
        // Largest first.
        ->and($rows[0]['key'])->toBe('workshop')
        // And the split adds up to the figure it splits.
        ->and(array_sum(array_column($rows, 'value')))->toBe(4);

    $none = collect($rows)->firstWhere('key', null);

    expect($none)->not->toBeNull()
        ->and($none['label'])->toBe(__('events::cp.metric_no_type'))
        ->and($none['label'])->not->toBe('events::cp.metric_no_type');
});

/**
 * The same for a status the database holds but the enum cannot express.
 *
 * Written with the query builder on purpose: the model casts this column to an
 * enum, so an empty status cannot be created through it. A bad import or a hand
 * patched row can still produce one, and the metrics read the table as it *is*
 * rather than as the model wishes it were. The row is grouped and labelled, not
 * dropped.
 */
it('keeps a date whose status is empty in the split', function () {
    insightsFixture();

    $event = Event::query()->first();

    DB::table('event_occurrences')->insert([
        'brand_id' => $event->brand_id,
        'event_id' => $event->id,
        'uuid' => (string) Str::uuid(),
        'starts_at' => '2026-08-18 19:00:00',
        'all_day' => false,
        'status' => '',
        'sequence' => 0,
        'venue_name' => 'Kulturzentrum',
        'created_at' => '2026-08-01 12:00:00',
        'updated_at' => '2026-08-01 12:00:00',
    ]);

    $window = insightsWindow();
    $rows = (new Occurrences)->breakdown($window, 'status');

    expect(insightsKeyed($rows))->toBe([
        OccurrenceStatus::Scheduled->value => 2,
        OccurrenceStatus::Cancelled->value => 2,
        '' => 1,
    ])
        ->and(array_sum(array_column($rows, 'value')))->toBe((new Occurrences)->value($window));

    expect(collect($rows)->firstWhere('key', null)['label'])->toBe(__('events::cp.metric_no_status'));
});

/** A split nobody offers is empty, not an error. */
it('answers an unknown split with nothing', function () {
    insightsFixture();

    $window = insightsWindow();

    expect((new Published)->breakdown($window, 'weather'))->toBe([])
        ->and((new Occurrences)->breakdown($window, 'type'))->toBe([])
        ->and(array_keys((new Published)->breakdowns()))->toBe(['type'])
        ->and(array_keys((new Occurrences)->breakdowns()))->toBe(['status']);
});

/** Largest first, and no more than asked for. */
it('orders a split by size and respects the limit', function () {
    insightsFixture();

    $rows = (new Published)->breakdown(insightsWindow(), 'type', 1);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['key'])->toBe('workshop')
        ->and($rows[0]['value'])->toBe(2);
});

// -- Nothing to measure -------------------------------------------------------

/**
 * No tables, no answer, and not a zero.
 *
 * "Nothing to measure" and "measured nothing" are different statements, and a
 * zero for the first is the quiet kind of wrong: it puts a confident 0 on a
 * dashboard for a site that has not installed the calendar at all.
 */
it('cannot answer without the tables', function () {
    expect((new Published)->available())->toBeTrue();

    // A second, empty database rather than dropping the tables in this one.
    // Dropping them would leave the suite unable to roll its own migrations
    // back, and a test that breaks its neighbours' teardown reports the wrong
    // failure everywhere afterwards.
    config()->set('database.connections.without_events', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    $previous = DB::getDefaultConnection();
    DB::purge('without_events');
    DB::setDefaultConnection('without_events');

    try {
        foreach ([Published::class, Occurrences::class, Cancelled::class] as $class) {
            $metric = new $class;

            expect($metric->available())->toBeFalse($class.' answered without its table.')
                ->and($metric->value(insightsWindow()))->toBeNull()
                ->and($metric->series(insightsWindow()))->toBe([]);
        }

        expect((new Published)->breakdown(insightsWindow(), 'type'))->toBe([]);
    } finally {
        DB::setDefaultConnection($previous);
    }
});

// -- Time ---------------------------------------------------------------------

/**
 * The window is compared in UTC, because the columns are UTC.
 *
 * The period arrives from a screen that built it with `Carbon::now()` in the
 * application's timezone — America/Chicago in this suite, which is five hours
 * behind UTC in August. "11. to 20. August" asked there begins at 05:00 UTC on
 * the 11th.
 *
 * So an event published at 03:00 UTC on the 11th was published at 22:00 on the
 * *tenth* where the reader is standing, and must not be in their August window.
 * Without the conversion the bound is written into the SQL as the literal
 * `2026-08-11 00:00:00` and the row is counted: a whole window shifted by the
 * offset, silently, in the one place an off-by-one is invisible.
 */
it('compares the window in UTC and not in the application timezone', function () {
    expect(config('app.timezone'))->toBe('America/Chicago');

    Event::factory()->create([
        'type' => 'workshop',
        'status' => EventStatus::Published->value,
        'published_at' => insightsMoment('2026-08-11 03:00:00'),
    ]);

    Event::factory()->create([
        'type' => 'workshop',
        'status' => EventStatus::Published->value,
        'published_at' => insightsMoment('2026-08-11 06:00:00'),
    ]);

    $asked = new MetricQuery(Period::between(
        Carbon::parse('2026-08-11', 'America/Chicago')->startOfDay(),
        Carbon::parse('2026-08-20', 'America/Chicago')->endOfDay(),
    ));

    expect((new Published)->value($asked))->toBe(1);
});

// -- Brands -------------------------------------------------------------------

/**
 * A dashboard shows the brand the reader is in, exactly like every other read.
 *
 * The metrics query with the builder, which no global scope ever sees, so the
 * condition is applied by hand as a mirror of `BrandScope`. Without it the one
 * screen in the installation that ignores the brand would be the one that
 * summarises the whole business.
 */
it('counts only the current brand when there is more than one', function () {
    $this->enableMultiBrand();

    $alpha = $this->makeBrand('alpha');
    $beta = $this->makeBrand('beta');

    $publish = fn () => Event::factory()->create([
        'type' => 'workshop',
        'status' => EventStatus::Published->value,
        'published_at' => insightsMoment('2026-08-12 10:00:00'),
    ]);

    app('brand-context')->runFor($alpha, $publish);
    app('brand-context')->runFor($beta, $publish);
    app('brand-context')->runFor($beta, $publish);

    app('brand-context')->setCurrent($alpha);
    expect((new Published)->value(insightsWindow()))->toBe(1);

    app('brand-context')->setCurrent($beta);
    expect((new Published)->value(insightsWindow()))->toBe(2)
        ->and((new Published)->series(insightsWindow()))->toBe(['2026-08-12' => 2]);
});

/**
 * No current brand, no rows — the same fail-closed posture as `BrandScope`.
 *
 * An empty report is a question somebody asks. A report full of another brand's
 * numbers is a breach nobody notices.
 */
it('shows nothing rather than everything when no brand is current', function () {
    $this->enableMultiBrand();

    $alpha = $this->makeBrand('alpha');

    app('brand-context')->runFor($alpha, fn () => Event::factory()->create([
        'type' => 'workshop',
        'status' => EventStatus::Published->value,
        'published_at' => insightsMoment('2026-08-12 10:00:00'),
    ]));

    app('brand-context')->forget();

    expect(app('brand-context')->hasCurrent())->toBeFalse()
        ->and(app('brand-context')->failMode())->toBe('closed')
        ->and((new Published)->value(insightsWindow()))->toBe(0)
        ->and((new Published)->breakdown(insightsWindow(), 'type'))->toBe([]);
});

/** In single-brand mode the scope is a no-op, and so is the condition. */
it('applies no brand condition on a single-brand install', function () {
    insightsFixture();

    expect(app('brand-context')->multiBrandEnabled())->toBeFalse()
        ->and((new Published)->value(insightsWindow()))->toBe(4);
});

// -- What was promised --------------------------------------------------------

/** The handles are a contract. They end up in saved dashboards and in URLs. */
it('names its handles, units and group', function () {
    $expected = [
        [Published::class, 'events.published', Unit::COUNT],
        [Occurrences::class, 'events.occurrences', Unit::COUNT],
        [Cancelled::class, 'events.cancelled', Unit::COUNT],
    ];

    foreach ($expected as [$class, $handle, $unit]) {
        $metric = new $class;

        expect($metric->handle())->toBe($handle)
            ->and($metric->unit())->toBe($unit)
            ->and($metric->group())->toBe(__('events::cp.metric_group'))
            ->and($metric->label())->not->toBe('')
            ->and($metric->description())->not->toBeEmpty()
            // Nothing here is money or a duration, so nothing needs a formatter
            // hint beyond its unit.
            ->and($metric->meta(insightsWindow()))->toBe([]);

        // Translated, not a raw key left on the screen.
        expect($metric->label())->not->toContain('events::cp.');
    }
});

/**
 * The provider hands all three to the sibling, lazily and by handle.
 *
 * By class name rather than instance, so booting this addon does not build three
 * metric objects on a request that renders none of them.
 */
it('offers every metric to the sibling, lazily and by handle', function () {
    expect(InsightsStandIn::$root->registered)->toBe([
        'events.published' => Published::class,
        'events.occurrences' => Occurrences::class,
        'events.cancelled' => Cancelled::class,
    ]);
});

/**
 * A sibling that is there but not ready costs nothing.
 *
 * The facade exists in this suite for every test, and with no manager behind it
 * the registration has to be an early return rather than an exception — a
 * half-installed dashboard must never take a calendar down with it.
 */
it('does not throw when the sibling has no manager behind its facade', function () {
    InsightsStandIn::$root = null;
    app()->getProvider(ServiceProvider::class)->forgetInsightsMetrics();

    app()->getProvider(ServiceProvider::class)->bootAddon();

    expect(Event::query()->count())->toBe(0);
});

// -- All of time --------------------------------------------------------------

/**
 * Over an open-ended period, a row with no timestamp is still in no period.
 *
 * `Period::fromPreset('all')` has no bounds, and `TableMetric::inPeriod()`
 * windows through `when()` clauses on exactly those bounds — so over all time
 * neither clause is applied and **no condition survives**. On the nullable
 * columns this addon counts on, that is not "everything since the beginning"
 * but every row in the table: every draft would count as a publication, and
 * every date that was never called off would count as a cancellation.
 *
 * The fixture makes the difference impossible to miss. Two dates carry a
 * `cancelled_at`; five exist. Without the `whereNotNull` this test reports five
 * cancellations, and the metric that is most wrong is the one whose figure
 * looks most alarming.
 */
it('counts no undated row over an open-ended period', function () {
    insightsFixture();

    $everything = new MetricQuery(Period::fromPreset('all'), MetricQuery::BUCKET_MONTH);

    expect((new Published)->value($everything))->toBe(5, 'the four in the window and the July one, never the draft')
        ->and((new Occurrences)->value($everything))->toBe(5)
        ->and((new Cancelled)->value($everything))->toBe(2, 'two dates were called off; the other three were not');

    expect((new Published)->series($everything))->toBe(['2026-07' => 1, '2026-08' => 4])
        ->and((new Cancelled)->series($everything))->toBe(['2026-07' => 1, '2026-08' => 1])
        ->and((new Occurrences)->series($everything))->toBe(['2026-08' => 4, '2026-09' => 1]);

    // And no bucket without a name: strftime() of a NULL is NULL, which would
    // otherwise collect the undated rows in a column the axis cannot label.
    foreach ([new Published, new Occurrences, new Cancelled] as $metric) {
        expect(array_keys($metric->series($everything)))->not->toContain('')
            ->and(array_keys($metric->series($everything)))->not->toContain(null);
    }

    // The splits are windowed by the same method and inherit the same guard.
    expect(insightsKeyed((new Published)->breakdown($everything, 'type')))
        ->toBe(['workshop' => 2, 'concert' => 2, '' => 1]);
});

// -- One brand at a time ------------------------------------------------------

/**
 * Two brands, and every figure below belongs to exactly one of them.
 *
 * Nordlicht publishes once and holds two dates, one of which is called off
 * inside the window. Aurora publishes three times, holds four dates and calls
 * two of them off. Across both: 4 publications, 6 dates, 3 cancellations —
 * which are the numbers that appear the moment a query is not scoped, and are
 * therefore written into the messages of the assertions.
 *
 * Created through `runFor()` rather than by stamping `brand_id` on the row: a
 * date inherits its brand from its event, and it can only do that while the
 * event is readable, which under a global scope means while its own brand is
 * the current one. A fixture that wrote the column directly would produce rows
 * the addon itself cannot create.
 */
function insightsBrandFixture(int $nordlicht, int $aurora): void
{
    app('brand-context')->runFor($nordlicht, function () {
        $event = Event::factory()->create([
            'type' => 'workshop',
            'status' => EventStatus::Published->value,
            'published_at' => insightsMoment('2026-08-12 10:00:00'),
        ]);

        Occurrence::factory()->create([
            'event_id' => $event->id,
            'starts_at' => insightsMoment('2026-08-13 19:00:00'),
        ]);

        Occurrence::factory()->create([
            'event_id' => $event->id,
            'starts_at' => insightsMoment('2026-08-17 19:00:00'),
            'status' => OccurrenceStatus::Cancelled->value,
            'cancelled_at' => insightsMoment('2026-08-14 09:00:00'),
        ]);
    });

    app('brand-context')->runFor($aurora, function () {
        // Three concerts, all on the same evening, two of them called off.
        foreach ([
            ['2026-08-15 10:00:00', true],
            ['2026-08-16 10:00:00', true],
            ['2026-08-17 10:00:00', false],
        ] as [$published, $cancelled]) {
            $event = Event::factory()->create([
                'type' => 'concert',
                'status' => EventStatus::Published->value,
                'published_at' => insightsMoment($published),
            ]);

            Occurrence::factory()->create(array_merge([
                'event_id' => $event->id,
                'starts_at' => insightsMoment('2026-08-18 19:00:00'),
            ], $cancelled ? [
                'status' => OccurrenceStatus::Cancelled->value,
                'cancelled_at' => insightsMoment('2026-08-18 08:00:00'),
            ] : []));
        }

        // A draft with a date already in the calendar. It is in no publication
        // figure and in every date figure, which is what keeps the two apart.
        Occurrence::factory()->create([
            'event_id' => Event::factory()->create([
                'type' => 'concert',
                'status' => EventStatus::Draft->value,
            ])->id,
            'starts_at' => insightsMoment('2026-08-19 19:00:00'),
        ]);
    });
}

/**
 * The figure, the chart and the split all stop at the brand's edge.
 *
 * Three surfaces in one test because they are three queries, and in the
 * hand-written brand filters this replaced they had drifted apart before: a
 * figure that narrowed while its chart did not is a screen where the columns do
 * not add up to the number above them, and nothing on it says why.
 */
it('counts only the current brand’s calendar', function () {
    $this->enableMultiBrand();

    $nordlicht = $this->makeBrand('nordlicht')->id;
    $aurora = $this->makeBrand('aurora')->id;

    insightsBrandFixture($nordlicht, $aurora);

    app('brand-context')->setCurrent($nordlicht);

    $window = insightsWindow();

    expect((new Published)->value($window))->toBe(1, 'four events were published across the brands')
        ->and((new Occurrences)->value($window))->toBe(2, 'six dates fall in this window across the brands')
        ->and((new Cancelled)->value($window))->toBe(1, 'three dates were called off across the brands');

    expect((new Published)->series($window))->toBe(['2026-08-12' => 1])
        ->and((new Occurrences)->series($window))->toBe(['2026-08-13' => 1, '2026-08-17' => 1])
        ->and((new Cancelled)->series($window))->toBe(['2026-08-14' => 1]);

    expect(insightsKeyed((new Published)->breakdown($window, 'type')))
        ->toBe(['workshop' => 1], 'the concerts are Aurora’s');

    // Canonicalised: the split orders by size, and these two rows are the same
    // size. Asserting an order the SQL never promised is a test that fails on
    // the next engine rather than on the next defect.
    expect(insightsKeyed((new Occurrences)->breakdown($window, 'status')))
        ->toEqualCanonicalizing(['scheduled' => 1, 'cancelled' => 1]);
});

/**
 * The other brand, from the same rows.
 *
 * The mirror of the test above. Without it a filter that was a constant, or one
 * resolved once and cached, would pass everything up to here.
 */
it('gives another brand another set of numbers', function () {
    $this->enableMultiBrand();

    $nordlicht = $this->makeBrand('nordlicht')->id;
    $aurora = $this->makeBrand('aurora')->id;

    insightsBrandFixture($nordlicht, $aurora);

    app('brand-context')->setCurrent($aurora);

    $window = insightsWindow();

    expect((new Published)->value($window))->toBe(3)
        ->and((new Occurrences)->value($window))->toBe(4)
        ->and((new Cancelled)->value($window))->toBe(2);

    expect(insightsKeyed((new Published)->breakdown($window, 'type')))->toBe(['concert' => 3]);
    expect((new Cancelled)->series($window))->toBe(['2026-08-18' => 2]);
});

/**
 * No current brand: no rows, and still a metric.
 *
 * The wider half of the `Published`-only check further up — all three figures,
 * both tables — with the part that check does not make: **the tile has to stay
 * on the screen.** `available()` answers whether the thing exists at all, and a
 * brand that has not been resolved yet is not the metric ceasing to exist. A
 * tile reading zero can be understood; a tile that vanished cannot.
 */
it('reads zero for a brand nobody has resolved, without losing the metric', function () {
    $this->enableMultiBrand();

    insightsBrandFixture($this->makeBrand('nordlicht')->id, $this->makeBrand('aurora')->id);

    app('brand-context')->forget();

    $window = insightsWindow();

    foreach ([new Published, new Occurrences, new Cancelled] as $metric) {
        expect($metric->available())->toBeTrue($metric::class.' left the screen instead of reading zero')
            ->and($metric->value($window))->toBe(0, $metric::class.' answered for brands nobody had chosen')
            ->and($metric->series($window))->toBe([]);
    }

    expect((new Published)->breakdown($window, 'type'))->toBe([])
        ->and((new Occurrences)->breakdown($window, 'status'))->toBe([]);
});

/**
 * Single-brand: not one condition more than the rest of the addon applies.
 *
 * The check above it makes this point for `events`; this one makes it for the
 * dates as well, which are the rows that carry a brand of their own rather than
 * their event's. It is the half that is easy to get wrong in the other
 * direction: a filter that ran anyway would file every date written before
 * brand-context existed under a brand it never had, and an ordinary calendar's
 * figures would quietly go to zero.
 */
it('leaves the dates unfiltered on a single-brand install too', function () {
    $this->enableMultiBrand();

    insightsBrandFixture($this->makeBrand('nordlicht')->id, $this->makeBrand('aurora')->id);

    config()->set('brand-context.multi_brand', false);
    app('brand-context')->forget();

    $window = insightsWindow();

    expect((new Published)->value($window))->toBe(4)
        ->and((new Occurrences)->value($window))->toBe(6)
        ->and((new Cancelled)->value($window))->toBe(3);
});

/** A cross-brand admin operation is the one place the whole thing steps aside. */
it('reaches every brand inside an explicit bypass', function () {
    $this->enableMultiBrand();

    $nordlicht = $this->makeBrand('nordlicht')->id;

    insightsBrandFixture($nordlicht, $this->makeBrand('aurora')->id);

    app('brand-context')->setCurrent($nordlicht);

    $window = insightsWindow();

    expect(app('brand-context')->withoutBrandScope(fn () => (new Occurrences)->value($window)))->toBe(6)
        ->and((new Occurrences)->value($window))->toBe(2);
});

/**
 * A second provider object registers again, into the registry it was handed.
 *
 * This is the Octane case written down. `MetricRegistry` is a container
 * singleton, so a registration is a fact about one application instance —
 * while a worker keeps the *class* between requests and builds a new
 * application for each. With the "already registered" flag on the class, the
 * second request's registry was told it had been filled and was never offered
 * anything: the calendar's tiles were simply absent for the life of that
 * worker, with no error anywhere.
 */
it('offers its metrics again to the next application, not only to the first', function () {
    $second = insightsCollector();
    InsightsStandIn::$root = $second;

    (new ServiceProvider(app()))->bootAddon();

    expect($second->registered)->toBe([
        'events.published' => Published::class,
        'events.occurrences' => Occurrences::class,
        'events.cancelled' => Cancelled::class,
    ]);
});
