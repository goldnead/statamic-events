<?php

namespace Goldnead\Events\Tags;

use Goldnead\Events\EventManager;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Events\Rsvps;
use Goldnead\Events\Support\RsvpPolicy;
use Statamic\Facades\User;
use Statamic\Tags\Tags;

/**
 * The Antlers surface.
 *
 * These names and parameters are the contract a site developer builds templates
 * on, so they are semver-locked from the first release and were named once,
 * deliberately:
 *
 *   {{ events }}                    the events themselves
 *   {{ events:occurrences }}        dates, the general form
 *   {{ events:upcoming }}           dates from now on, cancelled ones dropped
 *   {{ events:next }}               the next attendable date, or nothing
 *   {{ events:count }}              how many dates match
 *   {{ events:feed_url }}           the subscribable calendar URL
 *   {{ events:rsvp }}               RSVP counts and the visitor's own answer
 *
 * Every one of them reads through the `Events` manager rather than building its
 * own query, which is what keeps the visibility rules in one place. A tag that
 * assembled its own `where` would eventually forget one, and forgetting one here
 * means a private workshop rendered on a public page.
 */
class Events extends Tags
{
    protected static $handle = 'events';

    /** {{ events }} — parameters: type, limit, listable */
    public function index()
    {
        $events = app(EventManager::class)->events($this->baseFilters());

        if ($limit = (int) $this->params->int('limit', 0)) {
            $events = $events->take($limit);
        }

        return $events->map(fn ($event) => $this->augmentEvent($event))->all();
    }

    /**
     * {{ events:occurrences }} — parameters: event, type, from, to, limit,
     * order, include_cancelled, listable
     */
    public function occurrences()
    {
        return $this->occurrenceList($this->occurrenceFilters());
    }

    /**
     * {{ events:upcoming }} — the same, from now on, with cancelled dates
     * dropped.
     *
     * `from` still works: it moves the horizon rather than being ignored, which
     * is what a "what's on next month" listing needs.
     */
    public function upcoming()
    {
        return $this->occurrenceList(array_merge($this->occurrenceFilters(), [
            'upcoming' => true,
            'include_cancelled' => $this->params->bool('include_cancelled', false),
            'order' => 'asc',
        ]));
    }

    /** {{ events:next }} — a single occurrence, or nothing to loop over. */
    public function next()
    {
        $occurrence = app(EventManager::class)->next($this->occurrenceFilters());

        return $occurrence ? [$this->augmentOccurrence($occurrence)] : [];
    }

    /** {{ events:count }} — the number of matching dates. */
    public function count(): int
    {
        return app(EventManager::class)->occurrenceQuery($this->occurrenceFilters())->count();
    }

    /**
     * {{ events:feed_url }} — the subscribable calendar URL.
     *
     * Takes `type`, so a page showing only concerts can offer a feed of only
     * concerts.
     */
    public function feedUrl(): string
    {
        $type = (string) $this->params->get('type', '');

        return route('statamic.events.feed').($type === '' ? '' : '?type='.urlencode($type));
    }

    /**
     * {{ events:rsvp event="<uuid>" }} — counts and the visitor's own answer.
     *
     * One row: `enabled`, `going`, `interested`, `not_going`, `status` (null for
     * guests or no answer) and `can_respond`. Never names anybody. With RSVP
     * switched off, or an event the visitor may not read, `enabled` is false and
     * the rest is empty.
     */
    public function rsvp(): array
    {
        $event = Event::where('uuid', (string) $this->params->get('event', ''))->first();
        $user = User::current();
        $user = $user instanceof \Statamic\Auth\User ? $user : null;

        if (! RsvpPolicy::enabled() || ! $event || ! $event->isPubliclyReadable()) {
            return [['enabled' => false, 'can_respond' => false, 'status' => null, 'going' => 0, 'interested' => 0, 'not_going' => 0]];
        }

        $rsvps = app(Rsvps::class);

        return [['enabled' => true, 'can_respond' => RsvpPolicy::canRespond($user, $event), 'status' => $rsvps->statusFor($event, $user)?->value] + $rsvps->counts($event)];
    }

    /** {{ events:ics_url occurrence="<uuid>" }} — the per-date download. */
    public function icsUrl(): ?string
    {
        $uuid = (string) $this->params->get('occurrence', '');

        return $uuid === '' ? null : route('statamic.events.occurrence', ['uuid' => $uuid]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function occurrenceList(array $filters): array
    {
        return app(EventManager::class)->occurrences($filters)
            ->map(fn (Occurrence $occurrence) => $this->augmentOccurrence($occurrence))
            ->all();
    }

    /** @return array<string, mixed> */
    private function baseFilters(): array
    {
        return [
            'type' => $this->params->get('type'),
            // Templates render for visitors, so the default is the narrow one:
            // public events only. `listable="false"` opens it up to unlisted
            // events for the page that already knows which one it is showing.
            'listable' => $this->params->bool('listable', true),
        ];
    }

    /** @return array<string, mixed> */
    private function occurrenceFilters(): array
    {
        return array_merge($this->baseFilters(), [
            'event' => $this->params->get('event'),
            'from' => $this->params->get('from'),
            'to' => $this->params->get('to'),
            'limit' => $this->params->get('limit'),
            'order' => (string) $this->params->get('order', 'asc'),
            'include_cancelled' => $this->params->bool('include_cancelled', true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function augmentEvent(Event $event): array
    {
        return [
            'id' => $event->uuid,
            'title' => $event->title,
            'slug' => $event->slug,
            'description' => $event->description,
            'type' => $event->type,
            'visibility' => $event->visibility->value,
            'timezone' => $event->timezone,
            'published_at' => $event->published_at,
            'feed_url' => route('statamic.events.feed'),
        ];
    }

    /**
     * The shape a template sees for one date.
     *
     * `starts_at` is the *local* start — the date in its own zone, which is what
     * a page about a concert in Tokyo should print. `starts_at_utc` is there for
     * anyone doing arithmetic, and `timezone` so a template can label what it
     * just printed.
     *
     * @return array<string, mixed>
     */
    private function augmentOccurrence(Occurrence $occurrence): array
    {
        return [
            'id' => $occurrence->uuid,
            'starts_at' => $occurrence->localStart(),
            'ends_at' => $occurrence->localEnd(),
            'starts_at_utc' => $occurrence->starts_at,
            'ends_at_utc' => $occurrence->ends_at,
            'timezone' => $occurrence->effectiveTimezone(),
            'all_day' => (bool) $occurrence->all_day,
            'cancelled' => $occurrence->isCancelled(),
            'cancellation_reason' => $occurrence->cancellation_reason,
            'online' => $occurrence->isOnline(),
            'online_url' => $occurrence->online_url,
            'venue_name' => $occurrence->venue_name,
            'venue_address' => $occurrence->venue_address,
            'venue_city' => $occurrence->venue_city,
            'venue_country' => $occurrence->venue_country,
            'location' => $occurrence->locationLine(),
            'ics_url' => route('statamic.events.occurrence', ['uuid' => $occurrence->uuid]),
            'event' => $occurrence->event ? $this->augmentEvent($occurrence->event) : null,
        ];
    }
}
