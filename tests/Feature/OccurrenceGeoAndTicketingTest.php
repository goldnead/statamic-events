<?php

use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Events\Support\Blueprints;

/*
 * A postal code and a coordinate pair are the two things a city name cannot do:
 * be compared by distance, and be drawn on a map. These tests guard the shape
 * rather than the arithmetic — the radius query itself belongs to whoever asks
 * the distance question, not to the occurrence.
 */

it('keeps a postal code exactly as it was written', function () {
    $event = Event::factory()->create();

    $wien = $event->occurrences()->create([
        'starts_at' => now()->addWeek(),
        'venue_name' => 'Theater Akzent',
        'venue_postal_code' => 'A-1070',
        'venue_city' => 'Wien',
        'venue_country' => 'AT',
    ]);

    // Not cast to an integer, not trimmed to five digits: every Austrian,
    // Dutch and British postal code dies on that assumption.
    expect($wien->fresh()->venue_postal_code)->toBe('A-1070');
});

it('stores coordinates precisely enough to place a venue', function () {
    $event = Event::factory()->create();

    $occurrence = $event->occurrences()->create([
        'starts_at' => now()->addWeek(),
        'venue_name' => 'Kelterhaus',
        'venue_postal_code' => '76698',
        'venue_city' => 'Ubstadt-Weiher',
        'venue_latitude' => 49.1592369,
        'venue_longitude' => 8.6332836,
    ]);

    $fresh = $occurrence->fresh();

    expect((float) $fresh->venue_latitude)->toBe(49.1592369)
        ->and((float) $fresh->venue_longitude)->toBe(8.6332836);
});

it('leaves the three fields empty rather than guessing them', function () {
    $event = Event::factory()->create();

    $occurrence = $event->occurrences()->create([
        'starts_at' => now()->addWeek(),
        'venue_name' => 'Aula des Immanuel-Kant-Gymnasiums',
    ]);

    // An address line without a postal code is common in the wild. Filling it
    // with a placeholder would make a bad radius result look like a real one.
    expect($occurrence->fresh()->venue_postal_code)->toBeNull()
        ->and($occurrence->fresh()->venue_latitude)->toBeNull()
        ->and($occurrence->fresh()->venue_longitude)->toBeNull();
});

it('offers all three in the occurrence form', function () {
    $handles = collect(Blueprints::occurrence(Event::factory()->create())->fields()->all())
        ->keys();

    expect($handles)->toContain('venue_postal_code')
        ->toContain('venue_latitude')
        ->toContain('venue_longitude')
        ->toContain('tickets_url')
        ->toContain('is_free');
});

it('keeps the ticket link on the date, not on the event', function () {
    $event = Event::factory()->create();

    $sold = $event->occurrences()->create([
        'starts_at' => now()->addWeek(),
        'venue_name' => 'Burghof',
        'tickets_url' => 'https://tickets.test/loerrach',
    ]);

    $free = $event->occurrences()->create([
        'starts_at' => now()->addWeeks(2),
        'venue_name' => 'Marktplatz',
        'is_free' => true,
    ]);

    // Two dates of one event sell differently. An event-level link would send
    // everyone to the first night.
    expect($sold->fresh()->tickets_url)->toBe('https://tickets.test/loerrach')
        ->and($sold->fresh()->is_free)->toBeFalse()
        ->and($free->fresh()->tickets_url)->toBeNull()
        ->and($free->fresh()->is_free)->toBeTrue();
});

it('treats a date with neither link as unsold rather than free', function () {
    $event = Event::factory()->create();

    $occurrence = $event->occurrences()->create([
        'starts_at' => now()->addWeek(),
        'venue_name' => 'Kulturzentrum',
    ]);

    expect($occurrence->fresh()->is_free)->toBeFalse();
});

it('rejects a coordinate that is not on the globe', function () {
    $blueprint = Blueprints::occurrence(Event::factory()->create());

    $rules = $blueprint->fields()->validator()->rules();

    expect($rules['venue_latitude'])->toContain('between:-90,90')
        ->and($rules['venue_longitude'])->toContain('between:-180,180');
});
