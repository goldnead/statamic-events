<?php

use Goldnead\Events\Http\Controllers\Cp\EventController;
use Goldnead\Events\Http\Controllers\Cp\OccurrenceController;
use Illuminate\Support\Facades\Route;

// The kill switch has to bite here as well as on the nav item. Hiding the entry
// while leaving the screens reachable by URL is not a disabled Control Panel.
if (! config('events.cp.enabled', true)) {
    return;
}

/*
| Route parameters are `{event}` and `{occurrence}` and nothing is bound to
| either name. An implicit route-model binding would claim the name application
| wide, and a sibling addon that then puts `{event}` in one of its own routes
| gets this addon's binder — which resolves against this addon's tables, finds
| nothing and 404s a page that has nothing to do with events. That is not
| hypothetical: LeadHub's delete button died exactly this way.
|
| See tests/Feature/RouteParameterCollisionTest.php.
*/

Route::prefix('events')->name('events.')->group(function () {
    Route::get('/', [EventController::class, 'index'])->name('index');
    Route::get('create', [EventController::class, 'create'])->name('create');
    Route::post('/', [EventController::class, 'store'])->name('store');

    // `create` is matched before `{event}` and `{event}` only matches digits, so
    // neither can swallow the other.
    Route::get('{event}', [EventController::class, 'show'])->name('show')->whereNumber('event');
    Route::get('{event}/edit', [EventController::class, 'edit'])->name('edit')->whereNumber('event');
    Route::patch('{event}', [EventController::class, 'update'])->name('update')->whereNumber('event');
    Route::delete('{event}', [EventController::class, 'destroy'])->name('destroy')->whereNumber('event');

    Route::prefix('{event}/occurrences')->name('occurrences.')->group(function () {
        Route::get('create', [OccurrenceController::class, 'create'])->name('create')->whereNumber('event');
        Route::post('/', [OccurrenceController::class, 'store'])->name('store')->whereNumber('event');
    });

    // Editing a date does not need its event in the path: the row knows which
    // event it belongs to, and a URL that carries both invites the two halves to
    // disagree.
    Route::prefix('occurrences/{occurrence}')->name('occurrences.')->group(function () {
        Route::get('edit', [OccurrenceController::class, 'edit'])->name('edit')->whereNumber('occurrence');
        Route::patch('/', [OccurrenceController::class, 'update'])->name('update')->whereNumber('occurrence');
        Route::post('cancel', [OccurrenceController::class, 'cancel'])->name('cancel')->whereNumber('occurrence');
        Route::delete('/', [OccurrenceController::class, 'destroy'])->name('destroy')->whereNumber('occurrence');
    });
});
