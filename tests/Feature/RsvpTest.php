<?php

use Goldnead\Events\Enums\RsvpStatus;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Rsvp;
use Goldnead\Events\Rsvps;
use Goldnead\Events\Tags\Events as EventsTag;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Statamic\Facades\Antlers;
use Statamic\Facades\User;

function rsvpUser(string $name)
{
    $user = User::make()->email($name.'@example.test');
    $user->save();

    return $user;
}

function rsvpManager()
{
    $user = User::make()->email('manager@example.test')->makeSuper();
    $user->save();

    return $user;
}

beforeEach(fn () => config()->set('events.rsvp.enabled', true));

it('is off by default', function () {
    config()->set('events.rsvp.enabled', null);
    $event = Event::factory()->published()->create();

    expect(fn () => app(Rsvps::class)->toggle($event, rsvpUser('a')))->toThrow(AuthorizationException::class);
});

it('toggles going on and off', function () {
    $event = Event::factory()->published()->create();
    $user = rsvpUser('a');

    expect(app(Rsvps::class)->toggle($event, $user)->status)->toBe(RsvpStatus::Going);
    expect(app(Rsvps::class)->toggle($event, $user))->toBeNull();
    expect(Rsvp::count())->toBe(0);
});

it('keeps one answer per account and event', function () {
    $event = Event::factory()->published()->create();
    $user = rsvpUser('a');

    app(Rsvps::class)->set($event, $user, 'going');
    app(Rsvps::class)->set($event, $user, RsvpStatus::NotGoing);

    expect(Rsvp::count())->toBe(1)->and(Rsvp::first()->status)->toBe(RsvpStatus::NotGoing);
    expect(fn () => Rsvp::create(['brand_id' => $event->brand_id, 'event_id' => $event->id, 'user_id' => (string) $user->id(), 'status' => 'going']))
        ->toThrow(QueryException::class);
});

it('rejects an unknown status', function () {
    $event = Event::factory()->published()->create();

    expect(fn () => app(Rsvps::class)->set($event, rsvpUser('a'), 'maybe'))->toThrow(ValueError::class);
});

it('removes an answer', function () {
    $event = Event::factory()->published()->create();
    $user = rsvpUser('a');
    app(Rsvps::class)->set($event, $user, 'interested');

    app(Rsvps::class)->remove($event, $user);

    expect(Rsvp::count())->toBe(0);
});

it('counts per status', function () {
    $event = Event::factory()->published()->create();
    app(Rsvps::class)->set($event, rsvpUser('a'), 'going');
    app(Rsvps::class)->set($event, rsvpUser('b'), 'going');
    app(Rsvps::class)->set($event, rsvpUser('c'), 'interested');

    expect(app(Rsvps::class)->counts($event))->toBe(['going' => 2, 'interested' => 1, 'not_going' => 0]);
});

it('refuses answers for drafts and private events', function () {
    $user = rsvpUser('a');

    foreach ([Event::factory()->create(), Event::factory()->published()->private()->create()] as $event) {
        expect(fn () => app(Rsvps::class)->set($event, $user, 'going'))->toThrow(AuthorizationException::class);
    }
});

it('lets only those who manage events see names', function () {
    $event = Event::factory()->published()->create();
    $guest = rsvpUser('a');
    app(Rsvps::class)->set($event, $guest, 'going');

    expect(fn () => app(Rsvps::class)->list($event, $guest))->toThrow(AuthorizationException::class);
    expect(fn () => app(Rsvps::class)->list($event, null))->toThrow(AuthorizationException::class);
    expect(app(Rsvps::class)->list($event, rsvpManager()))->toHaveCount(1);
});

it('cascades with the event and erases per account', function () {
    $event = Event::factory()->published()->create();
    $other = Event::factory()->published()->create();
    $user = rsvpUser('a');
    app(Rsvps::class)->set($event, $user, 'going');
    app(Rsvps::class)->set($other, $user, 'going');

    expect(app(Rsvps::class)->eraseFor($user))->toBe(2);

    app(Rsvps::class)->set($event, $user, 'going');
    $event->delete();
    expect(Rsvp::count())->toBe(0);
});

function rsvpTag(Event $event): array
{
    return resolve(EventsTag::class)->setParser(Antlers::parser())->setContext([])
        ->setParameters(['event' => $event->uuid])->rsvp()[0];
}

it('gives the tag counts for everyone and a status for the signed-in account', function () {
    $event = Event::factory()->published()->create();
    $user = rsvpUser('a');
    app(Rsvps::class)->set($event, $user, 'interested');

    expect(rsvpTag($event))->toMatchArray(['enabled' => true, 'can_respond' => false, 'status' => null, 'interested' => 1]);

    $this->actingAs($user);
    expect(rsvpTag($event))->toMatchArray(['can_respond' => true, 'status' => 'interested']);
});

it('reports nothing from the tag when off or when the event is not readable', function () {
    $event = Event::factory()->published()->create();
    $draft = Event::factory()->create();

    expect(rsvpTag($draft)['enabled'])->toBeFalse();
    config()->set('events.rsvp.enabled', false);
    expect(rsvpTag($event))->toMatchArray(['enabled' => false, 'going' => 0]);
});

it('stores the programme as a clean list', function () {
    $event = Event::factory()->create(['program' => [
        ['id' => 'x', 'time' => '19:00', 'title' => 'Einsingen'],
        ['id' => 'y', 'time' => '20:00', 'title' => ''],
        ['time' => null, 'title' => 'Konzert'],
    ]]);

    expect($event->fresh()->program)->toBe([
        ['time' => '19:00', 'title' => 'Einsingen'],
        ['time' => null, 'title' => 'Konzert'],
    ]);
    expect(Event::factory()->create()->fresh()->program)->toBe([]);
});
