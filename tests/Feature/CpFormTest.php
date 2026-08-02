<?php

use Carbon\CarbonImmutable;
use Goldnead\Events\Enums\EventStatus;
use Goldnead\Events\Enums\Visibility;
use Goldnead\Events\Events\EventPublished;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * The two Control Panel forms are `Statamic\CP\PublishForm`, so what needs
 * testing is not the rendering — that is core's own page — but the two edges the
 * addon owns: what comes out of a blueprint submit and what goes back in.
 */

function manager()
{
    $handle = 'manager-'.Str::random(8);

    Role::make($handle)->addPermission(['access cp', 'view events', 'manage events'])->save();

    $user = User::make()->email(Str::random(8).'@example.test')->assignRole($handle);
    $user->save();

    return $user;
}

it('creates an event from a blueprint submit and redirects to it', function () {
    $response = $this->actingAs(manager())->post(cp_route('events.store'), [
        'title' => 'Chorworkshop Frankfurt',
        'slug' => 'chorworkshop-frankfurt',
        'description' => 'Two days on vowel shaping.',
        'type' => 'workshop',
        'status' => 'draft',
        'visibility' => 'public',
        'timezone' => 'Europe/Berlin',
    ])->assertOk();

    $event = Event::query()->firstOrFail();

    expect($event->title)->toBe('Chorworkshop Frankfurt')
        ->and($event->timezone)->toBe('Europe/Berlin')
        ->and($event->status)->toBe(EventStatus::Draft)
        ->and($event->visibility)->toBe(Visibility::Public);

    // `redirect` is the key core's PublishForm reads off the save response. A 302
    // instead makes the XHR follow it with the original verb and re-enter the
    // action until the browser gives up.
    $response->assertJsonPath('saved', true)
        ->assertJsonPath('redirect', cp_route('events.show', ['event' => $event->getKey()]));
});

it('goes through publish() when the form raises the status, so published_at is stamped once', function () {
    EventFacade::fake([EventPublished::class]);

    $event = Event::factory()->create(['timezone' => 'Europe/Berlin']);

    $this->actingAs(manager())->patch(cp_route('events.update', ['event' => $event->getKey()]), [
        'title' => $event->title,
        'slug' => $event->slug,
        'type' => $event->type,
        'status' => 'published',
        'visibility' => 'public',
        'timezone' => 'Europe/Berlin',
    ])->assertOk()->assertJsonPath('saved', true);

    $fresh = $event->fresh();

    expect($fresh->status)->toBe(EventStatus::Published)
        ->and($fresh->published_at)->not->toBeNull();

    EventFacade::assertDispatchedTimes(EventPublished::class, 1);
});

it('never lets a blueprint value reach brand_id', function () {
    // The models are `$guarded = []`, so a mass assign of whatever the form
    // carried would let a crafted field decide who can see the row.
    $event = Event::factory()->create();
    $originalBrand = $event->brand_id;

    $this->actingAs(manager())->patch(cp_route('events.update', ['event' => $event->getKey()]), [
        'title' => 'Renamed',
        'slug' => $event->slug,
        'type' => $event->type,
        'status' => 'draft',
        'visibility' => 'public',
        'timezone' => 'Europe/Berlin',
        'brand_id' => 999999,
        'uuid' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
    ])->assertOk();

    $fresh = $event->fresh();

    expect($fresh->brand_id)->toBe($originalBrand)
        ->and($fresh->uuid)->toBe($event->uuid)
        ->and($fresh->title)->toBe('Renamed');
});

it('round-trips a date through the form in the application timezone and stores UTC', function () {
    // Statamic's date fieldtype hands back a wall-clock string in
    // config('app.timezone') whatever zone the widget displayed, so that is the
    // one reading the controller may make of it.
    $event = Event::factory()->create(['timezone' => 'Europe/Berlin']);

    $this->actingAs(manager())->post(cp_route('events.occurrences.store', ['event' => $event->getKey()]), [
        // The shape the date fieldtype's Vue widget submits: an ISO-8601 Zulu
        // instant. Statamic then hands the controller a wall-clock string in
        // config('app.timezone'), which is the one reading it may make of it.
        'starts_at' => '2026-07-15T17:00:00.000Z',
        'ends_at' => '2026-07-15T19:00:00.000Z',
        'all_day' => false,
        'venue_name' => 'Alte Oper',
    ])->assertOk();

    $occurrence = Occurrence::query()->firstOrFail();

    expect($occurrence->starts_at->format('Y-m-d H:i'))->toBe('2026-07-15 17:00')
        // …and it renders at 19:00 to somebody in Frankfurt, which is the point.
        ->and($occurrence->localStart()->format('H:i'))->toBe('19:00');
});

it('refuses a date with no venue and no URL, as a field error', function () {
    $event = Event::factory()->create();

    $this->actingAs(manager())
        ->postJson(cp_route('events.occurrences.store', ['event' => $event->getKey()]), [
            'starts_at' => '2026-07-15T17:00:00.000Z',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('venue_name');

    expect(Occurrence::query()->count())->toBe(0);
});

it('refuses an end before its start, as a field error rather than a 500', function () {
    $event = Event::factory()->create();

    $this->actingAs(manager())
        ->postJson(cp_route('events.occurrences.store', ['event' => $event->getKey()]), [
            'starts_at' => '2026-07-15T17:00:00.000Z',
            'ends_at' => '2026-07-15T15:00:00.000Z',
            'venue_name' => 'Alte Oper',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ends_at');
});

it('routes a moved window through reschedule so the sequence rises', function () {
    $event = Event::factory()->create();
    $occurrence = Occurrence::factory()->for($event)->create([
        'starts_at' => CarbonImmutable::parse('2026-07-15 17:00', 'UTC'),
    ]);

    $this->actingAs(manager())->patch(cp_route('events.occurrences.update', ['occurrence' => $occurrence->getKey()]), [
        'starts_at' => '2026-07-22T17:00:00.000Z',
        'all_day' => false,
        'venue_name' => 'Alte Oper',
    ])->assertOk();

    $fresh = $occurrence->fresh();

    expect($fresh->sequence)->toBe(1)
        ->and($fresh->starts_at->format('Y-m-d H:i'))->toBe('2026-07-22 17:00')
        ->and($fresh->venue_name)->toBe('Alte Oper');
});

it('offers the previous date\'s location when a series gets another date', function () {
    // Ten dates in one venue should not be ten address entries.
    $event = Event::factory()->create();
    Occurrence::factory()->for($event)->create([
        'venue_name' => 'Alte Oper',
        'venue_city' => 'Frankfurt',
    ]);

    $this->actingAs(manager())
        ->get(cp_route('events.occurrences.create', ['event' => $event->getKey()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('PublishForm')
            ->where('values.venue_name', 'Alte Oper')
            ->where('values.venue_city', 'Frankfurt'));
});

it('hands the Control Panel no configuration and no tokens', function () {
    // The leak this exists to prevent: leadhub passed a whole config array to a
    // page as an Inertia prop. Nothing here may carry a key, a token or a config
    // section — only urls, labels and booleans the server already decided on.
    $event = Event::factory()->create();
    Occurrence::factory()->for($event)->create();

    $this->actingAs(manager())
        ->get(cp_route('events.show', ['event' => $event->getKey()]))
        ->assertOk()
        ->assertInertia(function ($page) {
            $props = json_encode($page->toArray()['props'] ?? []);

            foreach (['app_key', 'APP_KEY', 'secret', 'token', 'password', 'feeds', 'bridges'] as $forbidden) {
                expect(str_contains(strtolower($props), strtolower($forbidden)))->toBeFalse(
                    "The Show page hands [{$forbidden}] to the browser."
                );
            }
        });
});
