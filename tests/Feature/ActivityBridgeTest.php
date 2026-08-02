<?php

use Goldnead\Activity\Facades\Activity;
use Goldnead\Events\Bridges\ActivityBridge;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Events\ServiceProvider;

/*
 * statamic-activity is a `suggest`, never a requirement — a concert calendar on
 * an artist's website has to install without an audit trail. So it is not in
 * require-dev, and the class the bridge probes for is supplied by
 * tests/Fixtures/StandInActivityFacade.php instead.
 *
 * What that leaves untested by execution is the single `class_exists()` line in
 * `siblingIsPresent()`. It is the same early return the config-off case below
 * takes, and the same "no negative answer recorded" behaviour, which is the part
 * that could actually be wrong.
 */

beforeEach(function () {
    Activity::forget();
    ActivityBridge::forget();
});

it('attaches once and stays attached', function () {
    expect(ActivityBridge::attach(app()))->toBeTrue()
        ->and(ActivityBridge::isAttached())->toBeTrue()
        // Idempotent: the provider attempts the bridge and then registers two
        // retry hooks, and a test bed calls bootAddon() on top of that. Attaching
        // twice would put two listeners on every domain event and write every
        // cancellation to the ledger twice.
        ->and(ActivityBridge::attach(app()))->toBeTrue();
});

it('records one fact per domain event, however often it was booted', function () {
    $provider = app()->getProvider(ServiceProvider::class);

    $provider->bootAddon();
    $provider->bootAddon();
    ActivityBridge::attach(app());

    $event = Event::factory()->create();
    $event->publish();

    expect(collect(Activity::$recorded)->pluck('type')->all())->toBe(['events.event_published']);
});

it('sends the four domain events across with subject, brand and dedupe key', function () {
    ActivityBridge::attach(app());

    $event = Event::factory()->create();
    $event->publish();

    $occurrence = $event->occurrences()->create([
        'starts_at' => now()->addWeek(),
        'venue_name' => 'Kulturzentrum',
    ]);

    $occurrence->reschedule(now()->addWeeks(2));
    $occurrence->cancel('Storm damage');

    $recorded = collect(Activity::$recorded);

    expect($recorded->pluck('type')->all())->toBe([
        'events.event_published',
        'events.occurrence_scheduled',
        'events.occurrence_rescheduled',
        'events.occurrence_cancelled',
    ]);

    $cancelled = $recorded->last()['attributes'];

    expect($cancelled['subject'])->toBeInstanceOf(Occurrence::class)
        // Handed over explicitly rather than left to the ambient context: a date
        // cancelled from a console command has no current brand, and a fact filed
        // under the wrong brand is worse than no fact.
        ->and($cancelled['brand_id'])->toBe($occurrence->brand_id)
        ->and($cancelled['dedupe_key'])->toBe('events.occurrence_cancelled:'.$occurrence->uuid)
        ->and($cancelled['properties']['reason'])->toBe('Storm damage')
        ->and($cancelled['properties']['occurrence_uuid'])->toBe($occurrence->uuid);
});

it('makes each reschedule its own fact but a retry of one the same fact', function () {
    ActivityBridge::attach(app());

    $occurrence = Occurrence::factory()->create();

    $occurrence->reschedule(now()->addWeeks(2));
    $occurrence->reschedule(now()->addWeeks(3));

    $keys = collect(Activity::$recorded)
        ->where('type', 'events.occurrence_rescheduled')
        ->pluck('attributes.dedupe_key')
        ->all();

    // The sequence is what distinguishes them. Without it the ledger would
    // collapse two genuine moves into one.
    expect($keys)->toBe([
        'events.occurrence_rescheduled:'.$occurrence->uuid.':1',
        'events.occurrence_rescheduled:'.$occurrence->uuid.':2',
    ]);
});

it('declines when it is switched off in config, without latching', function () {
    config()->set('events.bridges.activity', false);

    expect(ActivityBridge::attach(app()))->toBeFalse()
        ->and(ActivityBridge::isAttached())->toBeFalse();

    // No negative answer is recorded, which is the whole reason the retries in
    // the provider are not decorative: Statamic calls bootAddon() from inside a
    // Statamic::booted() callback, and a nested $app->booted() written there
    // fires immediately rather than later.
    config()->set('events.bridges.activity', true);

    expect(ActivityBridge::attach(app()))->toBeTrue();
});

it('lets a cancellation succeed even when the ledger throws', function () {
    ActivityBridge::attach(app());

    Activity::$throws = new RuntimeException('ledger is misconfigured');

    $occurrence = Occurrence::factory()->create();

    // Calling off a concert must not depend on the optional addon next door being
    // healthy. This is the one place in the addon where swallowing is right.
    $occurrence->cancel('Storm damage');

    expect($occurrence->fresh()->isCancelled())->toBeTrue();
});

it('names no sibling addon as a Composer requirement', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true);

    expect(array_keys($composer['require']))->toBe([
        'php',
        'goldnead/statamic-brand-context',
        'laravel/framework',
        'statamic/cms',
    ])
        ->and(array_keys($composer['require-dev']))->not->toContain('goldnead/statamic-activity')
        ->and(array_keys($composer['suggest']))->toContain('goldnead/statamic-activity');
});
