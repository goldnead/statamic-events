<?php

use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Illuminate\Support\Str;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * Every write route gets its unauthorized case tested. That is the thinnest area
 * across the whole reference set of Statamic addons and the one that hurts most:
 * one of the largest third-party addons leaves its store, update and destroy
 * routes open to any authenticated Control Panel user.
 */

function userWith(array $permissions)
{
    $handle = 'role-'.Str::random(8);

    Role::make($handle)->addPermission($permissions)->save();

    $user = User::make()
        ->email(Str::random(8).'@example.test')
        ->assignRole($handle);

    $user->save();

    return $user;
}

function guestRoutes(Event $event, Occurrence $occurrence): array
{
    return [
        ['get', cp_route('events.index')],
        ['get', cp_route('events.show', ['event' => $event->getKey()])],
    ];
}

function writeRoutes(Event $event, Occurrence $occurrence): array
{
    return [
        ['get', cp_route('events.create')],
        ['post', cp_route('events.store')],
        ['get', cp_route('events.edit', ['event' => $event->getKey()])],
        ['patch', cp_route('events.update', ['event' => $event->getKey()])],
        ['delete', cp_route('events.destroy', ['event' => $event->getKey()])],
        ['get', cp_route('events.occurrences.create', ['event' => $event->getKey()])],
        ['post', cp_route('events.occurrences.store', ['event' => $event->getKey()])],
        ['get', cp_route('events.occurrences.edit', ['occurrence' => $occurrence->getKey()])],
        ['patch', cp_route('events.occurrences.update', ['occurrence' => $occurrence->getKey()])],
        ['post', cp_route('events.occurrences.cancel', ['occurrence' => $occurrence->getKey()])],
        ['delete', cp_route('events.occurrences.destroy', ['occurrence' => $occurrence->getKey()])],
    ];
}

it('sends an anonymous visitor to the login screen', function () {
    $event = Event::factory()->create();
    $occurrence = Occurrence::factory()->for($event)->create();

    foreach (array_merge(guestRoutes($event, $occurrence), writeRoutes($event, $occurrence)) as [$method, $url]) {
        $this->{$method}($url)->assertRedirect();
    }
});

it('refuses a Control Panel user without the permission', function () {
    $event = Event::factory()->create();
    $occurrence = Occurrence::factory()->for($event)->create();

    // A user who can reach the CP at all, and nothing more. Hiding the nav entry
    // from them is not authorization.
    $user = userWith(['access cp']);

    foreach (array_merge(guestRoutes($event, $occurrence), writeRoutes($event, $occurrence)) as [$method, $url]) {
        $this->actingAs($user)->{$method}($url)->assertForbidden();
    }
});

it('lets a read-only user read and nothing else', function () {
    $event = Event::factory()->create();
    $occurrence = Occurrence::factory()->for($event)->create();

    $user = userWith(['access cp', 'view events']);

    foreach (guestRoutes($event, $occurrence) as [$method, $url]) {
        $this->actingAs($user)->{$method}($url)->assertOk();
    }

    foreach (writeRoutes($event, $occurrence) as [$method, $url]) {
        $this->actingAs($user)->{$method}($url)->assertForbidden();
    }
});

it('opens every route to a user who may manage events', function () {
    $event = Event::factory()->create();
    $occurrence = Occurrence::factory()->for($event)->create();

    $user = userWith(['access cp', 'view events', 'manage events']);

    foreach ([
        ['get', cp_route('events.index')],
        ['get', cp_route('events.show', ['event' => $event->getKey()])],
        ['get', cp_route('events.create')],
        ['get', cp_route('events.edit', ['event' => $event->getKey()])],
        ['get', cp_route('events.occurrences.create', ['event' => $event->getKey()])],
        ['get', cp_route('events.occurrences.edit', ['occurrence' => $occurrence->getKey()])],
    ] as [$method, $url]) {
        $this->actingAs($user)->{$method}($url)->assertOk();
    }
});

it('registers no Control Panel routes at all when the kill switch is off', function () {
    // Hiding the nav entry while leaving the screens reachable by URL is not a
    // disabled Control Panel, so the route file itself has to register nothing.
    //
    // Loaded into isolated prefixes on the live router: the real one was built at
    // boot and the file uses the Route facade, so it cannot be pointed at a
    // throwaway router.
    $router = app('router');
    $before = $router->getRoutes()->count();

    config()->set('events.cp.enabled', false);
    $router->group(['prefix' => 'probe-off', 'as' => 'probe-off.'], __DIR__.'/../../routes/cp.php');

    expect($router->getRoutes()->count())->toBe($before);

    config()->set('events.cp.enabled', true);
    $router->group(['prefix' => 'probe-on', 'as' => 'probe-on.'], __DIR__.'/../../routes/cp.php');

    expect($router->getRoutes()->count())->toBeGreaterThan($before);
});
