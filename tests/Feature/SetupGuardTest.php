<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * The events screen with the addon installed and its migrations not run.
 *
 * That combination is ordinary — composer pulls the package in, the nav item
 * shows up, and `events` and `event_occurrences` still do not exist. The
 * listing asked both tables a question while the page was being built and the
 * screen answered HTTP 500. These tests reproduce that database and hold the
 * page to an empty state plus a line in the log.
 */

function setupGuardUser()
{
    $handle = 'role-'.Str::random(8);

    Role::make($handle)->addPermission(['access cp', 'view events'])->save();

    return tap(User::make()->email(Str::random(8).'@example.test')->assignRole($handle))->save();
}

function dropEventTables(): void
{
    // Dates first: the child table carries the foreign key.
    Schema::dropIfExists('event_occurrences');
    Schema::dropIfExists('events');
}

it('answers 200 on the index when its tables are missing', function () {
    dropEventTables();

    $this->actingAs(setupGuardUser())
        ->get(cp_route('events.index'))
        ->assertOk();
});

it('renders the setup screen and names both missing tables', function () {
    dropEventTables();

    $page = $this->actingAs(setupGuardUser())
        ->get(cp_route('events.index'))
        ->assertOk()
        ->viewData('page');

    expect($page['component'])->toBe('events::SetupRequired');
    // Both, because the listing counts each event's dates while rendering.
    expect($page['props']['tables'])->toContain('events')->toContain('event_occurrences');
    expect($page['props']['heading'])->not->toBeEmpty();
    expect($page['props']['description'])->not->toBeEmpty();
});

/*
 * The point of the guard is a readable page, not a quiet one. If this test ever
 * goes red the addon has traded a visible 500 for a silent nothing.
 */
it('writes the reason to the log', function () {
    dropEventTables();

    Log::spy();

    $this->actingAs(setupGuardUser())
        ->get(cp_route('events.index'))
        ->assertOk();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'statamic-events')
            && str_contains($message, 'php artisan migrate'))
        ->once();
});

/*
 * The <Listing> fetches its rows from the same action over JSON. Guarding only
 * the Inertia render would leave that request answering 500 — with the screen
 * looking fine and the rows never arriving.
 */
it('guards the JSON listing as well', function () {
    dropEventTables();

    $this->actingAs(setupGuardUser())
        ->getJson(cp_route('events.index'))
        ->assertOk();
});

it('still renders the listing on a migrated install', function () {
    $page = $this->actingAs(setupGuardUser())
        ->get(cp_route('events.index'))
        ->assertOk()
        ->viewData('page');

    expect($page['component'])->toBe('events::Events/Index');
});
