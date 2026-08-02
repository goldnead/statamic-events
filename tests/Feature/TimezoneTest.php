<?php

use Carbon\CarbonImmutable;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;

/*
 * The whole point of this file: the suite runs with app.timezone =
 * America/Chicago and no event uses it. A conversion that silently falls back to
 * the application timezone therefore fails here rather than in production.
 */

it('stores UTC whatever zone the caller handed in', function () {
    $event = Event::factory()->create(['timezone' => 'Europe/Berlin']);

    $occurrence = $event->occurrences()->create([
        // 19:00 in Berlin on a summer date is 17:00 UTC.
        'starts_at' => CarbonImmutable::parse('2026-07-15 19:00', 'Europe/Berlin'),
        'venue_name' => 'Alte Oper',
    ]);

    expect($occurrence->fresh()->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-07-15 17:00');
});

it('renders a date in the event\'s zone, not the viewer\'s and not the application\'s', function () {
    $event = Event::factory()->create(['timezone' => 'Asia/Tokyo']);

    $occurrence = $event->occurrences()->create([
        'starts_at' => CarbonImmutable::parse('2026-07-15 10:00', 'UTC'),
        'venue_name' => 'Suntory Hall',
    ]);

    expect($occurrence->effectiveTimezone())->toBe('Asia/Tokyo')
        ->and($occurrence->localStart()->format('Y-m-d H:i'))->toBe('2026-07-15 19:00')
        // Under America/Chicago the same instant reads 05:00. If that ever shows
        // up here, something is reading the app timezone.
        ->and($occurrence->localStart()->format('H:i'))->not->toBe('05:00');
});

it('lets a single date override the event\'s zone, which is what a tour needs', function () {
    $event = Event::factory()->create(['timezone' => 'Europe/Berlin']);

    $berlin = $event->occurrences()->create([
        'starts_at' => CarbonImmutable::parse('2026-07-15 17:00', 'UTC'),
        'venue_name' => 'Alte Oper',
    ]);

    $tokyo = $event->occurrences()->create([
        'starts_at' => CarbonImmutable::parse('2026-07-20 10:00', 'UTC'),
        'timezone' => 'Asia/Tokyo',
        'venue_name' => 'Suntory Hall',
    ]);

    expect($berlin->localStart()->format('H:i'))->toBe('19:00')
        ->and($tokyo->localStart()->format('H:i'))->toBe('19:00')
        ->and($berlin->effectiveTimezone())->toBe('Europe/Berlin')
        ->and($tokyo->effectiveTimezone())->toBe('Asia/Tokyo');
});

it('keeps the stored instant exact across a DST boundary', function () {
    // Berlin leaves DST on 2026-10-25. Two dates an hour apart in local terms
    // either side of it are two hours apart in UTC — which is the whole reason
    // for storing instants rather than wall clocks.
    $event = Event::factory()->create(['timezone' => 'Europe/Berlin']);

    $before = $event->occurrences()->create([
        'starts_at' => CarbonImmutable::parse('2026-10-24 20:00', 'Europe/Berlin'),
        'venue_name' => 'Alte Oper',
    ]);

    $after = $event->occurrences()->create([
        'starts_at' => CarbonImmutable::parse('2026-10-26 20:00', 'Europe/Berlin'),
        'venue_name' => 'Alte Oper',
    ]);

    expect($before->fresh()->starts_at->utc()->format('H:i'))->toBe('18:00')
        ->and($after->fresh()->starts_at->utc()->format('H:i'))->toBe('19:00')
        // …and both still read 20:00 to somebody standing in Frankfurt.
        ->and($before->localStart()->format('H:i'))->toBe('20:00')
        ->and($after->localStart()->format('H:i'))->toBe('20:00');
});

it('falls back to the event\'s zone and then to the configured default', function () {
    $event = Event::factory()->create(['timezone' => 'Europe/Berlin']);
    $occurrence = Occurrence::factory()->for($event)->create(['timezone' => null]);

    expect($occurrence->effectiveTimezone())->toBe('Europe/Berlin');

    config()->set('events.defaults.timezone', 'Europe/Lisbon');

    expect(Event::defaultTimezone())->toBe('Europe/Lisbon');
});
