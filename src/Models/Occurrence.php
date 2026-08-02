<?php

namespace Goldnead\Events\Models;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Events\Casts\UtcDateTime;
use Goldnead\Events\Database\Factories\OccurrenceFactory;
use Goldnead\Events\Enums\OccurrenceStatus;
use Goldnead\Events\Events\OccurrenceCancelled;
use Goldnead\Events\Events\OccurrenceRescheduled;
use Goldnead\Events\Events\OccurrenceScheduled;
use Goldnead\Events\Exceptions\InvalidOccurrenceWindow;
use Goldnead\Events\Exceptions\UnlocatableOccurrence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One date of an event.
 *
 * `starts_at` and `ends_at` are UTC instants, always. The zone they are *read*
 * in is the occurrence's own when it has one and the event's otherwise — a tour
 * plays in more than one timezone and every date belongs at its own place, not
 * at the viewer's.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $event_id
 * @property string $uuid
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property bool $all_day
 * @property string|null $timezone
 * @property OccurrenceStatus $status
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property string|null $venue_name
 * @property string|null $venue_address
 * @property string|null $venue_city
 * @property string|null $venue_country
 * @property string|null $online_url
 * @property int $sequence
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Occurrence extends Model
{
    use HasBrand;
    use HasFactory;

    protected $table = 'event_occurrences';

    protected $guarded = [];

    protected $casts = [
        'all_day' => 'boolean',
        'status' => OccurrenceStatus::class,
        'sequence' => 'integer',
    ];

    /*
     * The three timestamps are attribute accessors rather than entries in
     * $casts, and the accessors turn object caching off. Laravel's own
     * `datetime` cast never converts to UTC; a custom cast class and a cached
     * accessor both hand back the object that was assigned, in whatever zone it
     * had. See src/Casts/UtcDateTime.php.
     */

    protected function startsAt(): Attribute
    {
        return UtcDateTime::attribute();
    }

    protected function endsAt(): Attribute
    {
        return UtcDateTime::attribute();
    }

    protected function cancelledAt(): Attribute
    {
        return UtcDateTime::attribute();
    }

    /**
     * The factory lives in this package, not in the host application's
     * `Database\Factories` namespace, so Laravel's guess has to be corrected.
     */
    protected static function newFactory(): OccurrenceFactory
    {
        return OccurrenceFactory::new();
    }

    /**
     * Runs before bootTraits(), and therefore before HasBrand registers its own
     * `creating` listener — which is the point. HasBrand stamps the *ambient*
     * brand, and a console command or queued job has none, so an occurrence
     * created there would be filed under the default brand while its event sits
     * under another. Inheriting from the event first makes HasBrand's stamp a
     * no-op instead of a wrong answer.
     */
    protected static function booting(): void
    {
        static::creating(function (Occurrence $occurrence): void {
            if (empty($occurrence->brand_id) && $occurrence->event) {
                $occurrence->brand_id = $occurrence->event->brand_id;
            }
        });
    }

    protected static function booted(): void
    {
        static::creating(function (Occurrence $occurrence): void {
            $occurrence->uuid ??= (string) Str::uuid();
            $occurrence->status ??= OccurrenceStatus::Scheduled->value;

            $occurrence->assertLocatable();
            $occurrence->assertOrdered();
        });

        static::updating(function (Occurrence $occurrence): void {
            $occurrence->assertLocatable();
            $occurrence->assertOrdered();
        });

        static::created(function (Occurrence $occurrence): void {
            OccurrenceScheduled::dispatch($occurrence);
        });
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The IANA zone this date is read in: its own when set, the event's
     * otherwise.
     *
     * Deliberately not called `timezone()`: that is the raw, nullable column,
     * and having the two answer differently under names one character apart is
     * how a null reaches a DateTimeZone constructor.
     */
    public function effectiveTimezone(): string
    {
        return $this->timezone ?: ($this->event?->timezone ?: Event::defaultTimezone());
    }

    /** The start, expressed in the zone the date belongs to. */
    public function localStart(): CarbonImmutable
    {
        return $this->starts_at->setTimezone($this->effectiveTimezone());
    }

    public function localEnd(): ?CarbonImmutable
    {
        return $this->ends_at?->setTimezone($this->effectiveTimezone());
    }

    public function isCancelled(): bool
    {
        return $this->status === OccurrenceStatus::Cancelled;
    }

    public function isOnline(): bool
    {
        return filled($this->online_url);
    }

    public function hasVenue(): bool
    {
        return filled($this->venue_name);
    }

    /**
     * A one-line location for an ICS LOCATION property and for listings.
     *
     * Venue first: when a date is both in a room and streamed, the room is where
     * the people are.
     */
    public function locationLine(): ?string
    {
        if ($this->hasVenue()) {
            return collect([$this->venue_name, $this->venue_address, $this->venue_city, $this->venue_country])
                ->filter()
                ->implode(', ');
        }

        return $this->online_url ?: null;
    }

    /**
     * Moves the date and records where it came from.
     *
     * Sets the whole window: passing no end clears an existing one, because
     * "this now runs from 19:00" with a stale 21:00 still attached is a worse
     * answer than an open end.
     *
     * Bumps SEQUENCE, which is what makes a calendar client that already holds
     * this UID accept the new version instead of silently keeping the old one.
     * A move to the same instant is not a move: it emits nothing and leaves
     * SEQUENCE alone.
     */
    public function reschedule(mixed $startsAt, mixed $endsAt = null): self
    {
        $previousStart = $this->starts_at;
        $previousEnd = $this->ends_at;

        // Assigned raw: the UtcDateTime cast owns the conversion, and doing it a
        // second time here would give a bare string two different readings
        // depending on which door it came through.
        $this->starts_at = $startsAt;
        $this->ends_at = $endsAt;

        if (! $this->isDirty(['starts_at', 'ends_at'])) {
            return $this;
        }

        $this->sequence = $this->sequence + 1;
        $this->save();

        OccurrenceRescheduled::dispatch($this, $previousStart, $previousEnd);

        return $this;
    }

    /**
     * Cancels the date without deleting it.
     *
     * Deleting would be simpler and wrong: everyone who already subscribed holds
     * this UID, and the only way to tell them is to publish the same UID again
     * with STATUS:CANCELLED. Cancelling twice is a no-op rather than a second
     * announcement.
     */
    public function cancel(?string $reason = null): self
    {
        if ($this->isCancelled()) {
            return $this;
        }

        $this->status = OccurrenceStatus::Cancelled;
        $this->cancelled_at = CarbonImmutable::now();
        $this->cancellation_reason = $reason;
        $this->sequence = $this->sequence + 1;
        $this->save();

        OccurrenceCancelled::dispatch($this, $reason);

        return $this;
    }

    /**
     * At least one of venue or online URL. Enforced here rather than as a CHECK
     * constraint: a check across two nullable columns is not portable, and
     * SQLite — which this suite runs against by default — would not enforce it.
     */
    protected function assertLocatable(): void
    {
        if (! $this->hasVenue() && ! $this->isOnline()) {
            throw UnlocatableOccurrence::missingBoth();
        }
    }

    /** An end before its start is a typo, not a duration. */
    protected function assertOrdered(): void
    {
        if ($this->ends_at !== null && $this->ends_at->lessThan($this->starts_at)) {
            throw InvalidOccurrenceWindow::endsBeforeItStarts();
        }
    }

    /**
     * @param  Builder<Occurrence>  $query
     * @return Builder<Occurrence>
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', OccurrenceStatus::Scheduled->value);
    }

    /**
     * Everything starting at or after `$from` (default: now).
     *
     * Compared against `starts_at`, not `ends_at`: a two-day workshop that began
     * yesterday is no longer something anyone can start attending.
     */
    /**
     * @param  Builder<Occurrence>  $query
     * @return Builder<Occurrence>
     */
    public function scopeUpcoming(Builder $query, mixed $from = null): Builder
    {
        return $query->where('starts_at', '>=', CarbonImmutable::parse($from ?: CarbonImmutable::now())->utc());
    }

    /**
     * @param  Builder<Occurrence>  $query
     * @return Builder<Occurrence>
     */
    public function scopeStartsBetween(Builder $query, mixed $from = null, mixed $to = null): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->where('starts_at', '>=', CarbonImmutable::parse($from)->utc()))
            ->when($to, fn (Builder $q) => $q->where('starts_at', '<=', CarbonImmutable::parse($to)->utc()));
    }
}
