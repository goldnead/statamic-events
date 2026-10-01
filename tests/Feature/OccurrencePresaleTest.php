<?php

use Carbon\CarbonImmutable;
use Goldnead\Events\Events\OccurrencePresaleChanged;
use Goldnead\Events\Exceptions\InvalidOccurrenceWindow;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Events\Support\Blueprints;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/** Eigener Name, damit er nicht mit `manager()` und `terminManager()` kollidiert. */
function vvkManager()
{
    $handle = 'vvk-manager-'.Str::random(8);

    Role::make($handle)->addPermission(['access cp', 'view events', 'manage events'])->save();

    $user = User::make()->email(Str::random(8).'@example.test')->assignRole($handle);
    $user->save();

    return $user;
}

/*
 * The presale start is the second instant a sold date carries: not when the
 * room opens, but when the box office does. What is guarded here is its shape —
 * UTC on disk, in the form, and announced with where it came from. What a
 * mailing does with it belongs to whoever listens.
 */

it('stores the presale start as UTC, whatever zone the caller handed in', function () {
    $event = Event::factory()->create();

    $occurrence = $event->occurrences()->create([
        'starts_at' => CarbonImmutable::parse('2026-10-17 19:00', 'Europe/Berlin'),
        'venue_name' => 'Kulturzentrum',
        'presale_starts_at' => CarbonImmutable::parse('2026-09-01 10:00', 'Europe/Berlin'),
    ]);

    expect($occurrence->fresh()->presale_starts_at?->toDateTimeString())->toBe('2026-09-01 08:00:00');
});

it('leaves the presale start empty rather than guessing one', function () {
    $occurrence = Occurrence::factory()->create();

    // A date without a presale date is the normal case, not an error. `now()`
    // as a placeholder would fire a "presale has started" mailing on a day
    // nobody chose.
    expect($occurrence->fresh()->presale_starts_at)->toBeNull();
});

it('offers the field in the occurrence form, next to the ticket link', function () {
    $handles = collect(Blueprints::occurrence(Event::factory()->create())->fields()->all())
        ->keys();

    expect($handles)->toContain('presale_starts_at')
        ->and($handles->search('presale_starts_at'))->toBeLessThan($handles->search('tickets_url'));
});

it('fills the form with the stored presale start', function () {
    $occurrence = Occurrence::factory()->create([
        'presale_starts_at' => CarbonImmutable::parse('2026-09-01 08:00', 'UTC'),
    ]);

    $werte = $this->actingAs(vvkManager())
        ->get(cp_route('events.occurrences.edit', ['occurrence' => $occurrence->getKey()]))
        ->assertOk()
        ->viewData('page')['props']['values'];

    // The date fieldtype serialises as an ISO instant on the wire, the same
    // shape the update endpoint below sends back.
    expect($werte['presale_starts_at'])->toBe('2026-09-01T08:00:00.000Z');
});

it('emits OccurrencePresaleChanged when the presale start is set, from nothing', function () {
    EventFacade::fake([OccurrencePresaleChanged::class]);

    $occurrence = Occurrence::factory()->create();

    $occurrence->update(['presale_starts_at' => CarbonImmutable::parse('2026-09-01 08:00', 'UTC')]);

    EventFacade::assertDispatched(
        OccurrencePresaleChanged::class,
        // Null is "there was none", which is the whole message the first time
        // around: the presale has not moved, it has begun.
        fn (OccurrencePresaleChanged $event) => $event->occurrence->is($occurrence)
            && $event->previousPresaleStartsAt === null,
    );
});

it('carries the previous instant when the presale start moves', function () {
    EventFacade::fake([OccurrencePresaleChanged::class]);

    $occurrence = Occurrence::factory()->create([
        'presale_starts_at' => CarbonImmutable::parse('2026-09-01 08:00', 'UTC'),
    ]);

    $occurrence->update(['presale_starts_at' => CarbonImmutable::parse('2026-09-15 08:00', 'UTC')]);

    // "Der Vorverkauf beginnt jetzt später" needs the old day to be worth
    // sending. The raw original is read before syncOriginal() runs, which is
    // the ordering this test holds in place.
    EventFacade::assertDispatched(
        OccurrencePresaleChanged::class,
        fn (OccurrencePresaleChanged $event) => $event->previousPresaleStartsAt?->toDateTimeString() === '2026-09-01 08:00:00',
    );
});

it('does not emit when nothing about the presale changed', function () {
    EventFacade::fake([OccurrencePresaleChanged::class]);

    $occurrence = Occurrence::factory()->create([
        'presale_starts_at' => CarbonImmutable::parse('2026-09-01 08:00', 'UTC'),
    ]);

    // Another field, and the same instant handed back — neither is a change.
    $occurrence->update(['venue_name' => 'Burghof']);
    $occurrence->update(['presale_starts_at' => CarbonImmutable::parse('2026-09-01 08:00', 'UTC')]);

    EventFacade::assertNotDispatched(OccurrencePresaleChanged::class);
});

it('emits when the presale start is cleared, carrying the day that was', function () {
    EventFacade::fake([OccurrencePresaleChanged::class]);

    $occurrence = Occurrence::factory()->create([
        'presale_starts_at' => CarbonImmutable::parse('2026-09-01 08:00', 'UTC'),
    ]);

    $occurrence->update(['presale_starts_at' => null]);

    EventFacade::assertDispatchedTimes(OccurrencePresaleChanged::class, 1);
});

it('does not emit OccurrencePresaleChanged when the date is created with one', function () {
    EventFacade::fake([OccurrencePresaleChanged::class]);

    Occurrence::factory()->create([
        'presale_starts_at' => CarbonImmutable::parse('2026-09-01 08:00', 'UTC'),
    ]);

    // A new date announces itself through OccurrenceScheduled, presale date
    // included. A second event for the same row would let a listener fire
    // twice on one write.
    EventFacade::assertNotDispatched(OccurrencePresaleChanged::class);
});

it('refuses a presale start after the date, as a field error rather than a 500', function () {
    $occurrence = Occurrence::factory()->create([
        'starts_at' => CarbonImmutable::parse('2026-10-17 17:00', 'UTC'),
    ]);

    $this->actingAs(vvkManager())
        ->patchJson(cp_route('events.occurrences.update', ['occurrence' => $occurrence->getKey()]), [
            'starts_at' => '2026-10-17T17:00:00.000Z',
            'all_day' => false,
            'venue_name' => 'Kulturzentrum',
            'presale_starts_at' => '2026-10-20T10:00:00.000Z',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('presale_starts_at');

    expect($occurrence->fresh()->presale_starts_at)->toBeNull();
});

it('refuses a presale start after the date on the model as well', function () {
    $event = Event::factory()->create();

    // The controller check is the friendly path, not the authority. A second
    // write path that skips the form must not be able to store a presale that
    // opens after the room does.
    expect(fn () => $event->occurrences()->create([
        'starts_at' => CarbonImmutable::parse('2026-10-17 19:00', 'UTC'),
        'venue_name' => 'Kulturzentrum',
        'presale_starts_at' => CarbonImmutable::parse('2026-10-18 10:00', 'UTC'),
    ]))->toThrow(InvalidOccurrenceWindow::class);
});

it('accepts a presale start on the same day as the date', function () {
    $occurrence = Occurrence::factory()->create([
        'starts_at' => CarbonImmutable::parse('2026-10-17 19:00', 'UTC'),
    ]);

    // Equal is fine: a same-day presale is how a door-sale-only date with a
    // morning box office looks, and "before" would forbid it.
    $occurrence->update(['presale_starts_at' => CarbonImmutable::parse('2026-10-17 10:00', 'UTC')]);

    expect($occurrence->fresh()->presale_starts_at?->toDateTimeString())->toBe('2026-10-17 10:00:00');
});
