<?php

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\Events\Enums\OccurrenceStatus;
use Goldnead\Events\Events\OccurrenceCancelled;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * The dates on an event's screen are core's `<Listing>`, and the checkbox column
 * plus the "…" menu are only real if the two action endpoints behind them are.
 * Everything here goes through those endpoints rather than through the models,
 * because what broke in the playground broke there: an id the brand scope
 * removed left core comparing an empty collection against itself, deciding every
 * action in the Control Panel applied, and answering with a 500 from an unrelated
 * asset action.
 */

/**
 * The acting user. Called once per test and kept in a variable from there.
 *
 * Statamic without Pro allows exactly one user, so a second call inside the same
 * test fails with "Statamic Pro is required for multiple users" — which reads
 * like a licensing problem and is really a test writing one user too many.
 */
function actor(array $permissions = ['access cp', 'view events', 'manage events'])
{
    $handle = 'role-'.Str::random(8);

    Role::make($handle)->addPermission($permissions)->save();

    $user = User::make()->email(Str::random(8).'@example.test')->assignRole($handle);
    $user->save();

    return $user;
}

function occurrenceOn(Event $event, ?string $startsAt = null): Occurrence
{
    $start = CarbonImmutable::parse($startsAt ?? '2026-07-15 17:00', 'UTC');

    return $event->occurrences()->create([
        'starts_at' => $start,
        'ends_at' => $start->addHours(2),
        'venue_name' => 'Rotunde',
    ]);
}

it('offers cancelling and deleting for a scheduled date', function () {
    $occurrence = occurrenceOn(Event::factory()->create());

    $handles = collect($this->actingAs(actor())
        ->post(cp_route('events.occurrences.actions.bulk'), ['selections' => [$occurrence->getKey()]])
        ->assertOk()
        ->json())
        ->pluck('handle')
        ->all();

    expect($handles)->toBe(['events_cancel_occurrence', 'events_delete_occurrence']);
});

it('drops cancelling once a date is already cancelled', function () {
    $event = Event::factory()->create();
    $cancelled = occurrenceOn($event);
    $cancelled->cancel();

    $user = actor();

    $handles = collect($this->actingAs($user)
        ->post(cp_route('events.occurrences.actions.bulk'), ['selections' => [$cancelled->getKey()]])
        ->assertOk()
        ->json())
        ->pluck('handle')
        ->all();

    expect($handles)->toBe(['events_delete_occurrence']);

    // And a mixed selection loses it too: core's visibleToBulk asks every item.
    $scheduled = occurrenceOn($event);

    $mixed = collect($this->actingAs($user)
        ->post(cp_route('events.occurrences.actions.bulk'), [
            'selections' => [$cancelled->getKey(), $scheduled->getKey()],
        ])
        ->assertOk()
        ->json())
        ->pluck('handle')
        ->all();

    expect($mixed)->toBe(['events_delete_occurrence']);
});

it('cancels every checked date and sends the screen back to itself', function () {
    EventFacade::fake([OccurrenceCancelled::class]);

    $event = Event::factory()->create();
    $first = occurrenceOn($event);
    $second = occurrenceOn($event, '2026-07-22 17:00');

    $this->actingAs(actor())
        ->post(cp_route('events.occurrences.actions.run'), [
            'action' => 'events_cancel_occurrence',
            'selections' => [$first->getKey(), $second->getKey()],
            'values' => [],
            'context' => ['view' => 'list'],
        ])
        ->assertOk()
        // The listing runs client-side, so it has nothing to re-fetch. Without
        // the redirect the action succeeds and the table keeps the old rows.
        ->assertJsonPath('redirect', cp_route('events.show', ['event' => $event->getKey()]));

    expect($first->refresh()->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($second->refresh()->status)->toBe(OccurrenceStatus::Cancelled);

    EventFacade::assertDispatchedTimes(OccurrenceCancelled::class, 2);
});

it('deletes every checked date', function () {
    $event = Event::factory()->create();
    $first = occurrenceOn($event);
    $second = occurrenceOn($event, '2026-07-22 17:00');
    $kept = occurrenceOn($event, '2026-07-29 17:00');

    $this->actingAs(actor())
        ->post(cp_route('events.occurrences.actions.run'), [
            'action' => 'events_delete_occurrence',
            'selections' => [$first->getKey(), $second->getKey()],
            'values' => [],
            'context' => ['view' => 'list'],
        ])
        ->assertOk();

    expect(Occurrence::query()->pluck('id')->all())->toBe([$kept->getKey()]);
});

it('refuses a selection the brand scope does not reach', function () {
    $this->enableMultiBrand();

    $nord = $this->makeBrand('nord');
    $sued = $this->makeBrand('sued');

    $foreign = BrandContext::runFor($sued, fn () => occurrenceOn(Event::factory()->create()));

    // An id that resolves to nothing is not a selection to quietly shrink: an
    // empty collection satisfies every action core has registered.
    $user = BrandContext::runFor($nord, fn () => actor());

    BrandContext::runFor($nord, function () use ($foreign, $user) {
        $this->actingAs($user)
            ->post(cp_route('events.occurrences.actions.bulk'), ['selections' => [$foreign->getKey()]])
            ->assertNotFound();

        $this->actingAs($user)
            ->post(cp_route('events.occurrences.actions.run'), [
                'action' => 'events_cancel_occurrence',
                'selections' => [$foreign->getKey()],
                'values' => [],
                'context' => ['view' => 'list'],
            ])
            ->assertNotFound();
    });

    expect($foreign->refresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('keeps a read-only user out of both endpoints', function () {
    $occurrence = occurrenceOn(Event::factory()->create());

    $user = actor(['access cp', 'view events']);

    foreach (['events.occurrences.actions.bulk', 'events.occurrences.actions.run'] as $route) {
        $this->actingAs($user)
            ->post(cp_route($route), ['selections' => [$occurrence->getKey()]])
            ->assertForbidden();
    }
});

it('hands the screen sortable dates and the columns to show them in', function () {
    $event = Event::factory()->create(['timezone' => 'Europe/Berlin']);
    // 17:00 UTC on a summer date is 19:00 in Berlin, and the suite runs under
    // America/Chicago — so a conversion that falls back to the application
    // timezone reads 12:00 here and fails.
    $event->occurrences()->create([
        'starts_at' => CarbonImmutable::parse('2026-07-15 17:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-07-15 19:30', 'UTC'),
        'venue_name' => 'Alte Schmiede',
        'venue_city' => 'Kiel',
    ]);

    $props = $this->actingAs(actor())
        ->get(cp_route('events.show', ['event' => $event->getKey()]))
        ->assertOk()
        ->viewData('page')['props'];

    expect(collect($props['occurrenceColumns'])->pluck('field')->all())
        ->toBe(['starts_at', 'timezone', 'location', 'status']);

    $row = $props['occurrences'][0];

    // Client-side sorting compares the raw field, so `starts_at` has to be the
    // ISO value and the readable window has to travel beside it.
    expect($row['starts_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/')
        ->and($row['period_label'])->toContain('19:00 – 21:30')
        // The end does not repeat the date on the same day; that repetition costs
        // the column width the location needs.
        ->and(substr_count($row['period_label'], '2026'))->toBe(1)
        ->and($row['location'])->toBe('Alte Schmiede, Kiel')
        ->and($row['status_label'])->not->toBeEmpty()
        ->and($props['occurrenceActionUrl'])->toBe(cp_route('events.occurrences.actions.run'));
});
