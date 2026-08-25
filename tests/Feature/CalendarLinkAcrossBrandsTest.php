<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;

/**
 * "Add to calendar" has to work for a visitor.
 *
 * On a multi-brand installation it did not, and the way it failed is the
 * interesting part: the lookup ran through the brand scope, which fails closed,
 * and a website visitor has no brand — the Control Panel reads one from the
 * session, nobody else has one. So the button on every public page answered 404
 * while the row sat right there in the table. Nothing in the log, nothing in the
 * page, just a link that did nothing.
 *
 * The UUID is the address. That is what the route promises, and a uuid5 is not
 * guessable; what decides whether an occurrence may be handed out is the
 * visibility check, not the scope.
 */
beforeEach(function () {
    $this->enableMultiBrand();

    $this->nord = $this->makeBrand('nord');
    $this->sued = $this->makeBrand('sued');
});

it('serves an occurrence to a visitor who has no brand at all', function () {
    $termin = BrandContext::runFor($this->sued, function () {
        $event = Event::factory()->create(['title' => 'Tiefdruck, die Tour', 'visibility' => 'public']);
        $event->publish();

        return $event->occurrences()->create([
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
            'venue_name' => 'Rotunde',
        ]);
    });

    // No brand is current here — exactly the state of a website request.
    app('brand-context')->forget();

    $this->get("/!/events/occurrences/{$termin->uuid}.ics")
        ->assertOk()
        // ICS escapes a comma in a SUMMARY, so match the part that cannot change.
        ->assertSee('Tiefdruck');
});

it('serves it while another brand is current, without leaking the wrong one', function () {
    $termin = BrandContext::runFor($this->sued, function () {
        $event = Event::factory()->create(['title' => 'Süd-Konzert', 'visibility' => 'public']);
        $event->publish();

        return $event->occurrences()->create([
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
            'venue_name' => 'Halle',
        ]);
    });

    BrandContext::runFor($this->nord, function () use ($termin) {
        $this->get("/!/events/occurrences/{$termin->uuid}.ics")
            ->assertOk()
            ->assertSee('Süd-Konzert');
    });
});

it('still refuses what must not be handed out', function (string $sichtbarkeit, bool $veroeffentlicht) {
    $termin = BrandContext::runFor($this->sued, function () use ($sichtbarkeit, $veroeffentlicht) {
        $event = Event::factory()->create(['title' => 'Nicht für alle', 'visibility' => $sichtbarkeit]);

        if ($veroeffentlicht) {
            $event->publish();
        }

        return $event->occurrences()->create([
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
            'venue_name' => 'Halle',
        ]);
    });

    app('brand-context')->forget();

    // 404 and not 403: a 403 confirms the id exists.
    $this->get("/!/events/occurrences/{$termin->uuid}.ics")->assertNotFound();
})->with([
    'private' => ['private', true],
    'a draft' => ['public', false],
]);

it('keeps unlisted reachable by its link, which is what unlisted means', function () {
    $termin = BrandContext::runFor($this->sued, function () {
        $event = Event::factory()->create(['title' => 'Nur mit Link', 'visibility' => 'unlisted']);
        $event->publish();

        return $event->occurrences()->create([
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
            'venue_name' => 'Halle',
        ]);
    });

    app('brand-context')->forget();

    $this->get("/!/events/occurrences/{$termin->uuid}.ics")->assertOk();
});
