<?php

use Carbon\CarbonImmutable;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Events\Tags\Events as EventsTag;
use Statamic\Facades\Antlers;

/*
 * Tag names and parameters are semver-locked from the first release, so every
 * one the README promises gets a test.
 *
 * The tag is driven directly rather than through `Antlers::parse()`: under
 * Testbench the Antlers runtime never resolves addon tags at all — every
 * template renders to an empty string, including one calling a tag that does not
 * exist — so a green assertion there would prove nothing. This is the same
 * arrangement statamic-toc's TagTest uses, and it exercises the actual contract:
 * parameters in, augmented data out.
 */

function tag(array $parameters = []): EventsTag
{
    return resolve(EventsTag::class)
        ->setParser(Antlers::parser())
        ->setContext([])
        ->setParameters($parameters);
}

it('lists only listable events', function () {
    Event::factory()->published()->create(['title' => 'Public workshop']);
    Event::factory()->published()->unlisted()->create(['title' => 'Unlisted workshop']);
    Event::factory()->published()->private()->create(['title' => 'Private workshop']);
    Event::factory()->create(['title' => 'Draft workshop']);

    expect(collect(tag()->index())->pluck('title')->all())->toBe(['Public workshop']);
});

it('opens up to unlisted events when a template asks for it', function () {
    Event::factory()->published()->unlisted()->create(['title' => 'Unlisted workshop']);
    Event::factory()->published()->private()->create(['title' => 'Private workshop']);
    Event::factory()->create(['title' => 'Draft workshop']);

    // The page for one unlisted event already knows which one it is showing. It
    // still never reaches a draft or a private event.
    $titles = collect(tag(['listable' => false])->index())->pluck('title')->all();

    expect($titles)->toBe(['Unlisted workshop']);
});

it('narrows events by type', function () {
    Event::factory()->published()->create(['title' => 'Concert', 'type' => 'concert']);
    Event::factory()->published()->create(['title' => 'Workshop', 'type' => 'workshop']);

    expect(collect(tag(['type' => 'concert'])->index())->pluck('title')->all())->toBe(['Concert']);
});

it('renders each date in its own timezone', function () {
    $event = Event::factory()->published()->create(['timezone' => 'Asia/Tokyo']);
    $event->occurrences()->create([
        'starts_at' => CarbonImmutable::parse('2026-07-15 10:00', 'UTC'),
        'venue_name' => 'Suntory Hall',
    ]);

    $row = tag()->occurrences()[0];

    // 19:00 in Tokyo. Under the suite's America/Chicago it would read 05:00, and
    // the UTC value is offered separately for anyone doing arithmetic.
    expect($row['starts_at']->format('H:i'))->toBe('19:00')
        ->and($row['timezone'])->toBe('Asia/Tokyo')
        ->and($row['starts_at_utc']->format('H:i'))->toBe('10:00');
});

it('drops cancelled dates from upcoming and keeps them in occurrences', function () {
    $event = Event::factory()->published()->create();
    $kept = Occurrence::factory()->for($event)->create();
    $cancelled = Occurrence::factory()->for($event)->create();
    $cancelled->cancel('Storm damage');

    expect(collect(tag()->upcoming())->pluck('id')->all())->toBe([$kept->uuid])
        ->and(collect(tag()->occurrences())->pluck('id')->all())->toContain($cancelled->uuid);
});

it('gives the next attendable date and nothing when there is none', function () {
    $event = Event::factory()->published()->create();
    Occurrence::factory()->for($event)->past()->create();
    $soon = Occurrence::factory()->for($event)->create(['starts_at' => CarbonImmutable::now('UTC')->addDays(3)]);
    $later = Occurrence::factory()->for($event)->create(['starts_at' => CarbonImmutable::now('UTC')->addDays(30)]);

    expect(tag()->next()[0]['id'])->toBe($soon->uuid);

    // A cancelled next date is not the next date: "next" means one somebody can
    // attend.
    $soon->cancel();

    expect(tag()->next()[0]['id'])->toBe($later->uuid);

    Occurrence::query()->delete();

    expect(tag()->next())->toBe([]);
});

it('counts matching dates', function () {
    $event = Event::factory()->published()->create();
    Occurrence::factory()->count(3)->for($event)->create();
    Occurrence::factory()->for(Event::factory()->create())->create();

    // The draft event's date does not count: the tag answers what a visitor can
    // see, and so does its counter.
    expect(tag()->count())->toBe(3);
});

it('renders the feed and per-date URLs', function () {
    $occurrence = Occurrence::factory()->for(Event::factory()->published()->create())->create();

    expect(tag()->feedUrl())->toBe(route('statamic.events.feed'))
        ->and(tag(['type' => 'concert'])->feedUrl())->toBe(route('statamic.events.feed').'?type=concert')
        ->and(tag(['occurrence' => $occurrence->uuid])->icsUrl())
        ->toBe(route('statamic.events.occurrence', ['uuid' => $occurrence->uuid]))
        ->and(tag()->icsUrl())->toBeNull();
});

it('limits and orders dates', function () {
    $event = Event::factory()->published()->create();
    $first = Occurrence::factory()->for($event)->create(['starts_at' => CarbonImmutable::now('UTC')->addDays(1)]);
    $last = Occurrence::factory()->for($event)->create(['starts_at' => CarbonImmutable::now('UTC')->addDays(9)]);

    expect(tag(['limit' => 1, 'order' => 'asc'])->occurrences()[0]['id'])->toBe($first->uuid)
        ->and(tag(['limit' => 1, 'order' => 'desc'])->occurrences()[0]['id'])->toBe($last->uuid);
});

it('scopes dates to one event by slug', function () {
    $a = Event::factory()->published()->create(['slug' => 'workshop-a']);
    $b = Event::factory()->published()->create(['slug' => 'workshop-b']);
    $mine = Occurrence::factory()->for($a)->create();
    Occurrence::factory()->for($b)->create();

    expect(collect(tag(['event' => 'workshop-a'])->occurrences())->pluck('id')->all())->toBe([$mine->uuid]);
});

it('bounds a date range with from and to', function () {
    $event = Event::factory()->published()->create();
    Occurrence::factory()->for($event)->create(['starts_at' => CarbonImmutable::parse('2026-06-01 10:00', 'UTC')]);
    $inside = Occurrence::factory()->for($event)->create(['starts_at' => CarbonImmutable::parse('2026-07-15 10:00', 'UTC')]);
    Occurrence::factory()->for($event)->create(['starts_at' => CarbonImmutable::parse('2026-09-01 10:00', 'UTC')]);

    $ids = collect(tag(['from' => '2026-07-01', 'to' => '2026-07-31'])->occurrences())->pluck('id')->all();

    expect($ids)->toBe([$inside->uuid]);
});

it('carries the event onto every date so a listing needs one loop', function () {
    $event = Event::factory()->published()->create(['title' => 'Chorworkshop']);
    Occurrence::factory()->for($event)->create();

    $row = tag()->occurrences()[0];

    expect($row['event']['title'])->toBe('Chorworkshop')
        ->and($row['ics_url'])->toContain($row['id']);
});
