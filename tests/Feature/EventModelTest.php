<?php

use Goldnead\Events\Enums\EventStatus;
use Goldnead\Events\Events\EventPublished;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Illuminate\Support\Facades\Event as EventFacade;

it('fills the identifying fields a caller left out', function () {
    $event = Event::create(['title' => 'Chor-Workshop Frankfurt']);

    expect($event->uuid)->toBeString()->toHaveLength(36)
        ->and($event->slug)->toBe('chor-workshop-frankfurt')
        ->and($event->status)->toBe(EventStatus::Draft)
        ->and($event->timezone)->toBe(config('app.timezone'));
});

it('emits EventPublished on the transition and not on later saves', function () {
    EventFacade::fake([EventPublished::class]);

    $event = Event::factory()->create();

    EventFacade::assertNotDispatched(EventPublished::class);

    $event->publish();

    EventFacade::assertDispatchedTimes(EventPublished::class, 1);

    // A typo fixed on an already published event is not a second publication.
    $event->update(['title' => 'Corrected title']);

    EventFacade::assertDispatchedTimes(EventPublished::class, 1);
});

it('keeps the original published_at when an event is unpublished and published again', function () {
    $event = Event::factory()->create();

    $event->publish();
    $first = $event->published_at;

    expect($first)->not->toBeNull();

    $event->unpublish();
    $this->travel(2)->days();
    $event->publish();

    expect($event->fresh()->published_at->timestamp)->toBe($first->timestamp);
});

it('separates being published from being visible', function () {
    $draft = Event::factory()->create();
    $public = Event::factory()->published()->create();
    $unlisted = Event::factory()->published()->unlisted()->create();
    $private = Event::factory()->published()->private()->create();

    expect($draft->isPubliclyReadable())->toBeFalse()
        ->and($draft->isListable())->toBeFalse()
        // Unlisted is readable by link but never listed. Collapsing the two is
        // what would leak a private workshop into a public feed.
        ->and($unlisted->isPubliclyReadable())->toBeTrue()
        ->and($unlisted->isListable())->toBeFalse()
        ->and($private->isPubliclyReadable())->toBeFalse()
        ->and($public->isListable())->toBeTrue();
});

it('scopes listable to published public events only', function () {
    Event::factory()->create();
    Event::factory()->published()->unlisted()->create();
    Event::factory()->published()->private()->create();
    $listable = Event::factory()->published()->create();

    expect(Event::query()->listable()->pluck('id')->all())->toBe([$listable->id])
        ->and(Event::query()->published()->count())->toBe(3);
});

it('deletes an event\'s dates with it', function () {
    // The cascade is a real foreign key, which SQLite only honours with the
    // pragma the test bed sets. Without it this passes for the wrong reason.
    $event = Event::factory()->create();
    $event->occurrences()->createMany([
        ['starts_at' => now()->addWeek(), 'venue_name' => 'A'],
        ['starts_at' => now()->addWeeks(2), 'venue_name' => 'B'],
    ]);

    expect($event->occurrences()->count())->toBe(2);

    $event->delete();

    expect(Occurrence::query()->count())->toBe(0);
});
