<?php

namespace Goldnead\Events\Bridges;

use Goldnead\Activity\Facades\Activity;
use Goldnead\Events\Events\EventPublished;
use Goldnead\Events\Events\OccurrenceCancelled;
use Goldnead\Events\Events\OccurrenceRescheduled;
use Goldnead\Events\Events\OccurrenceScheduled;
use Goldnead\Events\Models\Occurrence;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Writes this addon's domain events into `statamic-activity`'s ledger, if that
 * addon happens to be installed.
 *
 * Never a Composer requirement — a concert calendar on an artist's website has
 * to install without a CRM or an audit trail. The direction is one way and stays
 * that way: events knows the name of activity's facade, activity knows nothing
 * about events.
 *
 * On the boundary itself: activity is the ledger of what *happened*, so what
 * crosses is the fact that a date was scheduled, moved or called off. The
 * terminverwaltung — the editing, the forms, the drafts — never does. A ledger
 * that also holds the plan is no longer a ledger.
 *
 * ## Why attaching is harder than it looks
 *
 * Statamic calls `bootAddon()` from inside a `Statamic::booted()` callback,
 * which itself runs inside the application's boot phase. Nesting another
 * `$app->booted()` there does not defer anything: `Application::booted()` fires
 * its callback immediately once the app is booted, so a "later" registration
 * written that way runs at once — before whatever it was waiting for. That is
 * the bug this class is shaped around.
 *
 * So instead of trusting one moment, `attach()` is called from several and is
 * idempotent: the first call that finds the sibling present wins, every later
 * one returns immediately. It never caches a *negative* answer, because the
 * whole point is that an earlier attempt may have been too early.
 */
class ActivityBridge
{
    /**
     * True once the listeners are on the dispatcher.
     *
     * Static because the provider, the retry hooks and any test bed all reach
     * for the same bridge, and a per-instance flag would let each of them attach
     * its own copy — which is how one cancellation ends up in the ledger three
     * times.
     */
    private static bool $attached = false;

    /**
     * Attaches once and reports whether the listeners are now in place.
     *
     * Returns false — without recording anything — when the sibling addon is not
     * installed or the bridge is switched off. A later call can still succeed.
     */
    public static function attach(?Application $app = null): bool
    {
        if (self::$attached) {
            return true;
        }

        if (! config('events.bridges.activity', true)) {
            return false;
        }

        if (! self::siblingIsPresent()) {
            return false;
        }

        Event::listen(EventPublished::class, [self::class, 'handleEventPublished']);
        Event::listen(OccurrenceScheduled::class, [self::class, 'handleOccurrenceScheduled']);
        Event::listen(OccurrenceRescheduled::class, [self::class, 'handleOccurrenceRescheduled']);
        Event::listen(OccurrenceCancelled::class, [self::class, 'handleOccurrenceCancelled']);

        self::$attached = true;

        return true;
    }

    public static function isAttached(): bool
    {
        return self::$attached;
    }

    /**
     * Test seam. Production never detaches — an addon that can be uninstalled at
     * runtime is not a thing.
     *
     * @internal
     */
    public static function forget(): void
    {
        self::$attached = false;
    }

    private static function siblingIsPresent(): bool
    {
        return class_exists(Activity::class);
    }

    public static function handleEventPublished(EventPublished $event): void
    {
        $model = $event->event;

        self::record('events.event_published', $model, [
            'event_uuid' => $model->uuid,
            'title' => $model->title,
            'type' => $model->type,
            'visibility' => $model->visibility->value,
        ], 'events.event_published:'.$model->uuid);
    }

    public static function handleOccurrenceScheduled(OccurrenceScheduled $event): void
    {
        $occurrence = $event->occurrence;

        self::record(
            'events.occurrence_scheduled',
            $occurrence,
            self::occurrenceProperties($occurrence),
            'events.occurrence_scheduled:'.$occurrence->uuid,
        );
    }

    public static function handleOccurrenceRescheduled(OccurrenceRescheduled $event): void
    {
        $occurrence = $event->occurrence;

        self::record(
            'events.occurrence_rescheduled',
            $occurrence,
            array_merge(self::occurrenceProperties($occurrence), [
                'previous_starts_at' => $event->previousStartsAt->utc()->toIso8601String(),
                'previous_ends_at' => $event->previousEndsAt?->utc()->toIso8601String(),
            ]),
            // The sequence is what makes each move distinct: two reschedules of
            // the same date are two facts, a retried write of one is not.
            'events.occurrence_rescheduled:'.$occurrence->uuid.':'.$occurrence->sequence,
        );
    }

    public static function handleOccurrenceCancelled(OccurrenceCancelled $event): void
    {
        $occurrence = $event->occurrence;

        self::record(
            'events.occurrence_cancelled',
            $occurrence,
            array_merge(self::occurrenceProperties($occurrence), ['reason' => $event->reason]),
            'events.occurrence_cancelled:'.$occurrence->uuid,
        );
    }

    /** @return array<string, mixed> */
    private static function occurrenceProperties(Occurrence $occurrence): array
    {
        return [
            'occurrence_uuid' => $occurrence->uuid,
            'event_uuid' => $occurrence->event?->uuid,
            'starts_at' => $occurrence->starts_at->utc()->toIso8601String(),
            'ends_at' => $occurrence->ends_at?->utc()->toIso8601String(),
            'timezone' => $occurrence->effectiveTimezone(),
            'sequence' => $occurrence->sequence,
        ];
    }

    /**
     * A failing ledger must not fail the write that produced the fact.
     *
     * Cancelling a concert has to succeed even when the optional addon next door
     * is misconfigured, so anything thrown on the way into the ledger is
     * reported and swallowed. This is the one place in the addon where that is
     * the right trade.
     *
     * `brand_id` is handed over explicitly rather than left to the ambient
     * context: a date cancelled from a console command has no current brand, and
     * a fact filed under the wrong brand is worse than no fact at all.
     *
     * @param  array<string, mixed>  $properties
     */
    private static function record(string $type, Model $subject, array $properties, string $dedupeKey): void
    {
        try {
            Activity::record($type, [
                'subject' => $subject,
                'brand_id' => $subject->getAttribute('brand_id'),
                'dedupe_key' => $dedupeKey,
                'properties' => array_filter($properties, fn ($value) => $value !== null),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
