<?php

use Goldnead\Events\Http\Controllers\CalendarController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public calendar routes
|--------------------------------------------------------------------------
|
| Statamic mounts this file under `/!/events/`, which is the documented addon
| namespace. Two reasons it lives here rather than in routes/web.php:
|
|  - `/!/` is reserved for addons, so an event page at /events/… on the site
|    itself never collides with the feed.
|  - The prefix is stable across installs, which matters more here than
|    anywhere else in the addon: a calendar subscription URL is copied into a
|    phone once and then never touched again. A configurable prefix would mean
|    a config change silently breaks every existing subscriber.
|
| Both routes are deliberately outside any auth middleware. A calendar client
| fetches them with no session.
|
*/

// Names are prefixed `events.` inside Statamic's own `statamic.` group, so the
// full names are `statamic.events.occurrence` and `statamic.events.feed`. An
// unprefixed `occurrence` would be a name any other addon could claim.
Route::name('events.')->group(function () {
    Route::get('occurrences/{uuid}.ics', [CalendarController::class, 'occurrence'])
        ->name('occurrence')
        ->where('uuid', '[0-9a-fA-F-]{36}');

    Route::get('calendar.ics', [CalendarController::class, 'feed'])->name('feed');
});
