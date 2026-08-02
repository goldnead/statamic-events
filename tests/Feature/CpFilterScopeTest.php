<?php

use Goldnead\Events\Query\Scopes\Filters\EventFilter;
use Goldnead\Events\ServiceProvider;
use Statamic\Facades\Scope;

it('offers exactly the filters the listing page expects', function () {
    // Pins the list, so a filter that is added to the folder but never registered
    // — or registered and never shown — fails here rather than going quietly
    // missing from the panel.
    $handles = collect(Scope::filters(EventFilter::LISTING_KEY))
        ->map(fn ($filter) => $filter->handle())
        ->sort()
        ->values()
        ->all();

    expect($handles)->toBe(['events_status', 'events_type', 'events_visibility']);
});

it('registers every filter class the provider names', function () {
    expect(ServiceProvider::LISTING_FILTERS)->toHaveCount(3);

    foreach (ServiceProvider::LISTING_FILTERS as $class) {
        expect(class_exists($class))->toBeTrue()
            ->and(is_subclass_of($class, EventFilter::class))->toBeTrue();
    }
});

it('shows none of them on any other listing', function () {
    // Statamic registers scopes globally and Filter::visibleTo() defaults to
    // true, so a filter that does not answer the question turns up on the
    // Entries, Assets and Users listings as well.
    foreach (ServiceProvider::LISTING_FILTERS as $class) {
        $filter = new $class;

        expect($filter->visibleTo(EventFilter::LISTING_KEY))->toBeTrue()
            ->and($filter->visibleTo('entries'))->toBeFalse()
            ->and($filter->visibleTo('users'))->toBeFalse()
            ->and($filter->visibleTo('assets'))->toBeFalse();
    }
});
