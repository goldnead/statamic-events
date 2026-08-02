<?php

use Carbon\CarbonImmutable;
use Goldnead\Events\Enums\OccurrenceStatus;
use Goldnead\Events\Events\OccurrenceCancelled;
use Goldnead\Events\Events\OccurrenceRescheduled;
use Goldnead\Events\Events\OccurrenceScheduled;
use Goldnead\Events\Exceptions\InvalidOccurrenceWindow;
use Goldnead\Events\Exceptions\UnlocatableOccurrence;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Illuminate\Support\Facades\Event as EventFacade;

it('refuses a date nobody can get to', function () {
    $event = Event::factory()->create();

    expect(fn () => $event->occurrences()->create(['starts_at' => now()->addWeek()]))
        ->toThrow(UnlocatableOccurrence::class);
});

it('accepts a venue alone, a URL alone, and both', function () {
    $event = Event::factory()->create();

    $venue = $event->occurrences()->create(['starts_at' => now()->addWeek(), 'venue_name' => 'Kulturzentrum']);
    $online = $event->occurrences()->create(['starts_at' => now()->addWeek(), 'online_url' => 'https://example.test/join']);
    $both = $event->occurrences()->create([
        'starts_at' => now()->addWeek(),
        'venue_name' => 'Kulturzentrum',
        'online_url' => 'https://example.test/join',
    ]);

    expect($venue->hasVenue())->toBeTrue()
        ->and($online->isOnline())->toBeTrue()
        // Venue first when a date is both in a room and streamed: the room is
        // where the people are.
        ->and($both->locationLine())->toStartWith('Kulturzentrum');
});

it('refuses an end before its start', function () {
    $event = Event::factory()->create();

    expect(fn () => $event->occurrences()->create([
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->subHour(),
        'venue_name' => 'Kulturzentrum',
    ]))->toThrow(InvalidOccurrenceWindow::class);
});

it('emits OccurrenceScheduled when a date is added', function () {
    EventFacade::fake([OccurrenceScheduled::class]);

    Occurrence::factory()->create();

    EventFacade::assertDispatchedTimes(OccurrenceScheduled::class, 1);
});

it('bumps the sequence and reports the previous window when a date moves', function () {
    EventFacade::fake([OccurrenceRescheduled::class]);

    $occurrence = Occurrence::factory()->create([
        'starts_at' => CarbonImmutable::parse('2026-09-01 17:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-01 19:00', 'UTC'),
    ]);

    $occurrence->reschedule('2026-09-08 17:00', '2026-09-08 19:00');

    expect($occurrence->fresh()->sequence)->toBe(1);

    EventFacade::assertDispatched(
        OccurrenceRescheduled::class,
        // The previous window is the part nobody can reconstruct afterwards, and
        // the part a "the workshop moved" notification actually needs.
        fn (OccurrenceRescheduled $event) => $event->previousStartsAt->toDateTimeString() === '2026-09-01 17:00:00'
            && $event->previousEndsAt->toDateTimeString() === '2026-09-01 19:00:00',
    );
});

it('treats a move to the same instant as no move at all', function () {
    EventFacade::fake([OccurrenceRescheduled::class]);

    $occurrence = Occurrence::factory()->create([
        'starts_at' => CarbonImmutable::parse('2026-09-01 17:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-09-01 19:00', 'UTC'),
    ]);

    $occurrence->reschedule('2026-09-01 17:00', '2026-09-01 19:00');

    expect($occurrence->fresh()->sequence)->toBe(0);
    EventFacade::assertNotDispatched(OccurrenceRescheduled::class);
});

it('cancels without deleting, and only once', function () {
    EventFacade::fake([OccurrenceCancelled::class]);

    $occurrence = Occurrence::factory()->create();

    $occurrence->cancel('Storm damage to the hall');

    $fresh = $occurrence->fresh();

    // The row survives on purpose: everyone who already subscribed holds this
    // UID, and the only way to reach them is to publish it again as cancelled.
    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($fresh->cancellation_reason)->toBe('Storm damage to the hall')
        ->and($fresh->sequence)->toBe(1);

    $occurrence->cancel('again');

    expect($occurrence->fresh()->sequence)->toBe(1);
    EventFacade::assertDispatchedTimes(OccurrenceCancelled::class, 1);
});

it('inherits the brand from its event rather than from the ambient context', function () {
    // A date created by a console command or a queued job has no current brand.
    // Falling back to the default there would file it under a brand its event
    // does not belong to.
    $event = Event::factory()->create();

    $occurrence = $event->occurrences()->create([
        'starts_at' => now()->addWeek(),
        'venue_name' => 'Kulturzentrum',
    ]);

    expect($occurrence->brand_id)->toBe($event->brand_id);
});
