<?php

use Carbon\CarbonImmutable;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Events\Support\Ics;

function occurrenceUrl(Occurrence $occurrence): string
{
    return route('statamic.events.occurrence', ['uuid' => $occurrence->uuid]);
}

it('serves an ICS for a published public date', function () {
    $event = Event::factory()->published()->create();
    $occurrence = Occurrence::factory()->for($event)->create();

    $this->get(occurrenceUrl($occurrence))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="'.app(Ics::class)->filename($occurrence->load('event')).'"');
});

it('serves an unlisted date to anyone holding the link', function () {
    // That is what "unlisted" means: the UUID is the capability. It still never
    // appears in a feed — see the feed test below.
    $event = Event::factory()->published()->unlisted()->create();
    $occurrence = Occurrence::factory()->for($event)->create();

    $this->get(occurrenceUrl($occurrence))->assertOk();
});

it('hides drafts and private events behind a 404, not a 403', function () {
    // A 403 confirms the id exists. For an endpoint whose only protection is an
    // unguessable URL, that is the whole protection given away.
    $draft = Occurrence::factory()->for(Event::factory()->create())->create();
    $private = Occurrence::factory()->for(Event::factory()->published()->private()->create())->create();

    $this->get(occurrenceUrl($draft))->assertNotFound();
    $this->get(occurrenceUrl($private))->assertNotFound();
});

it('404s an unknown uuid', function () {
    $this->get(route('statamic.events.occurrence', ['uuid' => '00000000-0000-4000-8000-000000000000']))
        ->assertNotFound();
});

it('puts only listable events in the feed', function () {
    $public = Occurrence::factory()->for(Event::factory()->published()->create())->create();
    $unlisted = Occurrence::factory()->for(Event::factory()->published()->unlisted()->create())->create();
    $private = Occurrence::factory()->for(Event::factory()->published()->private()->create())->create();
    $draft = Occurrence::factory()->for(Event::factory()->create())->create();

    $body = $this->get(route('statamic.events.feed'))->assertOk()->getContent();

    expect($body)->toContain($public->uuid)
        ->and($body)->not->toContain($unlisted->uuid)
        ->and($body)->not->toContain($private->uuid)
        ->and($body)->not->toContain($draft->uuid);
});

it('keeps a cancelled date in the feed', function () {
    $occurrence = Occurrence::factory()->for(Event::factory()->published()->create())->create();
    $occurrence->cancel('Storm damage');

    $body = $this->get(route('statamic.events.feed'))->assertOk()->getContent();

    // Dropping it is what leaves a cancelled concert in every subscriber's
    // calendar forever.
    expect($body)->toContain($occurrence->uuid)
        ->and($body)->toContain('STATUS:CANCELLED');
});

it('reaches only as far into the past as feeds.past_days allows', function () {
    config()->set('events.feeds.past_days', 1);

    $event = Event::factory()->published()->create();
    $recent = Occurrence::factory()->for($event)->create([
        'starts_at' => CarbonImmutable::now('UTC')->subHours(6),
        'ends_at' => null,
    ]);
    $old = Occurrence::factory()->for($event)->past()->create();

    $body = $this->get(route('statamic.events.feed'))->assertOk()->getContent();

    expect($body)->toContain($recent->uuid)
        ->and($body)->not->toContain($old->uuid);
});

it('narrows the feed by type and cannot be widened by one', function () {
    $concert = Occurrence::factory()->for(Event::factory()->published()->create(['type' => 'concert']))->create();
    $workshop = Occurrence::factory()->for(Event::factory()->published()->create(['type' => 'workshop']))->create();
    $privateWorkshop = Occurrence::factory()->for(Event::factory()->published()->private()->create(['type' => 'concert']))->create();

    $body = $this->get(route('statamic.events.feed', ['type' => 'concert']))->assertOk()->getContent();

    expect($body)->toContain($concert->uuid)
        ->and($body)->not->toContain($workshop->uuid)
        // `type` narrows an already-filtered set. It can never reach past the
        // visibility rules, whatever is typed into it.
        ->and($body)->not->toContain($privateWorkshop->uuid);
});

it('caps the feed so an unauthenticated endpoint cannot be made expensive', function () {
    config()->set('events.feeds.max_occurrences', 3);

    $event = Event::factory()->published()->create();
    Occurrence::factory()->count(10)->for($event)->create();

    $body = $this->get(route('statamic.events.feed'))->assertOk()->getContent();

    expect(substr_count($body, 'BEGIN:VEVENT'))->toBe(3);
});

it('404s the feed when it is switched off', function () {
    config()->set('events.feeds.enabled', false);

    $this->get(route('statamic.events.feed'))->assertNotFound();
});
