<?php

namespace Goldnead\Events;

use Carbon\CarbonImmutable;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The addon's public entry point, behind the `Events` facade.
 *
 * Named EventManager rather than Events, and bound under `statamic-events`
 * rather than `events`: Laravel binds `events` itself, to the event dispatcher.
 * Taking that key does not fail loudly — it replaces the dispatcher, and the
 * next framework provider to call `$app['events']->listen()` dies with
 * "Call to undefined method". The addon slug is not a safe container key.
 *
 * Everything a site developer reaches for goes through here, so the Antlers tags
 * and the calendar feed cannot drift apart: both call these methods rather than
 * assembling their own queries. The visibility rules in particular exist in one
 * place only — a feed that forgets one is a leak, and a tag that forgets one is
 * a bug report.
 */
class EventManager
{
    /**
     * Events a visitor may be shown.
     *
     * @param  array{type?: string|array<int, string>|null, listable?: bool}  $filters
     * @return Collection<int, Event>
     */
    public function events(array $filters = []): Collection
    {
        return $this->eventQuery($filters)->orderBy('title')->get();
    }

    /**
     * Occurrences a visitor may be shown, oldest first.
     *
     * `include_cancelled` defaults to true, which is the surprising-but-correct
     * answer: a cancelled date that silently disappears is how a choir turns up
     * to a cancelled rehearsal. Callers that want a clean "next up" list pass
     * false.
     *
     * @param  array{
     *     event?: Event|string|null,
     *     type?: string|array<int, string>|null,
     *     from?: mixed,
     *     to?: mixed,
     *     upcoming?: bool,
     *     include_cancelled?: bool,
     *     limit?: int|null,
     *     order?: string,
     *     listable?: bool
     * }  $filters
     * @return Collection<int, Occurrence>
     */
    public function occurrences(array $filters = []): Collection
    {
        return $this->occurrenceQuery($filters)->get();
    }

    /**
     * The next occurrence, or null.
     *
     * Cancelled dates are excluded here whatever the caller asks for: "the next
     * one" is a date somebody can attend.
     *
     * @param  array<string, mixed>  $filters
     */
    public function next(array $filters = []): ?Occurrence
    {
        return $this->occurrenceQuery(array_merge($filters, [
            'upcoming' => true,
            'include_cancelled' => false,
            'order' => 'asc',
            'limit' => 1,
        ]))->first();
    }

    /**
     * The occurrences a calendar feed may contain.
     *
     * Listable events only (published and public), reaching `feeds.past_days`
     * into the past so a date that finished this morning does not vanish from a
     * subscriber's calendar mid-day, and hard-capped: this is an unauthenticated
     * endpoint and without a cap one request can be made arbitrarily expensive
     * from the outside.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Occurrence>
     */
    public function feed(array $filters = []): Collection
    {
        $pastDays = max(0, (int) config('events.feeds.past_days', 1));

        return $this->occurrenceQuery(array_merge($filters, [
            'listable' => true,
            'from' => CarbonImmutable::now()->subDays($pastDays),
            'include_cancelled' => true,
            'order' => 'asc',
            'limit' => (int) config('events.feeds.max_occurrences', 500),
        ]))->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Event>
     */
    public function eventQuery(array $filters = []): Builder
    {
        /** @var Builder<Event> $query */
        $query = Event::query();

        $this->constrainEvents($query, $filters);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Occurrence>
     */
    public function occurrenceQuery(array $filters = []): Builder
    {
        /** @var Builder<Occurrence> $query */
        $query = Occurrence::query()->with('event');

        // A date is only as visible as its event. Expressed as a subquery
        // constraint rather than a join so the brand scope on both models keeps
        // applying — a join would let a caller widen it by accident.
        // whereHas() hands the closure a `Builder<Model>` because that is all its
        // own signature promises. At runtime it is the related model's builder —
        // an Event's — which is what constrainEvents() needs and what the
        // relationship above guarantees. One narrow, stated suppression rather
        // than a baseline entry that would also hide the next one.
        $query->whereHas('event', function ($events) use ($filters): void {
            /** @phpstan-ignore argument.type (whereHas passes the Event builder; the generic says Model) */
            $this->constrainEvents($events, $filters);
        });

        if (($filters['include_cancelled'] ?? true) === false) {
            $query->scheduled();
        }

        if ($filters['upcoming'] ?? false) {
            $query->upcoming($filters['from'] ?? null);
        } else {
            $query->startsBetween($filters['from'] ?? null, $filters['to'] ?? null);
        }

        $query->orderBy('starts_at', ($filters['order'] ?? 'asc') === 'desc' ? 'desc' : 'asc')
            ->orderBy('id');

        if (($limit = $filters['limit'] ?? null) !== null) {
            $query->limit(max(1, (int) $limit));
        }

        return $query;
    }

    /**
     * The visibility and selection rules, applied to whichever query asks — the
     * events query directly and the occurrences query through whereHas(). One
     * body, so the two can never disagree about who may see what.
     *
     * @param  Builder<Event>  $events
     * @param  array<string, mixed>  $filters
     */
    private function constrainEvents(Builder $events, array $filters): void
    {
        // `listable` narrows to public only. The fallback is addressable(), not
        // published(): published() includes private events, and using it here is
        // exactly the leak that put a private workshop on a public page before
        // the suite caught it.
        ($filters['listable'] ?? false) ? $events->listable() : $events->addressable();

        if (filled($filters['type'] ?? null)) {
            $events->ofType($this->list($filters['type']));
        }

        if ($event = $filters['event'] ?? null) {
            $event instanceof Event
                ? $events->whereKey($event->getKey())
                : $events->where('slug', (string) $event);
        }
    }

    /**
     * Splits a comma-separated tag parameter into a list.
     *
     * @param  string|array<int, string>  $value
     * @return array<int, string>
     */
    private function list(string|array $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
