<?php

namespace Goldnead\Events\Models;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Events\Casts\UtcDateTime;
use Goldnead\Events\Database\Factories\EventFactory;
use Goldnead\Events\Enums\EventStatus;
use Goldnead\Events\Enums\Visibility;
use Goldnead\Events\Events\EventPublished;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A thing that happens, described once.
 *
 * The description lives here and the dates live in `occurrences`. An
 * entry-per-date arrangement — the shape a Statamic collection with a date field
 * pushes you into — duplicates the description n times and leaves no place to
 * record that the third date is cancelled while the others still stand.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $uuid
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property string $type
 * @property Visibility $visibility
 * @property EventStatus $status
 * @property string $timezone
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Event extends Model
{
    use HasBrand;
    use HasFactory;

    protected $table = 'events';

    protected $guarded = [];

    protected $casts = [
        'visibility' => Visibility::class,
        'status' => EventStatus::class,
    ];

    /** Stored and read as UTC. See src/Casts/UtcDateTime.php. */
    protected function publishedAt(): Attribute
    {
        return UtcDateTime::attribute();
    }

    /**
     * The factory lives in this package, not in the host application's
     * `Database\Factories` namespace, so Laravel's guess has to be corrected.
     */
    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Event $event): void {
            $event->uuid ??= (string) Str::uuid();
            $event->slug = $event->slug ?: Str::slug($event->title);
            $event->type ??= 'other';
            $event->visibility ??= config('events.defaults.visibility', 'public');
            $event->status ??= EventStatus::Draft->value;
            $event->timezone = $event->timezone ?: static::defaultTimezone();
        });

        // Fired from `saved` rather than `saving`, so a listener sees the row as
        // it is stored. Guarded on the *transition*: a published event that is
        // saved again for a typo has not been published a second time, and a
        // listener sending an announcement must be able to rely on that.
        static::saved(function (Event $event): void {
            if ($event->wasChanged('status') && $event->status === EventStatus::Published) {
                EventPublished::dispatch($event);
            }
        });
    }

    public static function defaultTimezone(): string
    {
        return config('events.defaults.timezone') ?: config('app.timezone') ?: 'UTC';
    }

    /** @return HasMany<Occurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Occurrence::class)->orderBy('starts_at');
    }

    /**
     * Publishes the event, stamping `published_at` the first time only.
     *
     * Re-publishing after an unpublish keeps the original date: it is the date
     * the event became public, and a feed consumer that sorts on it should not
     * see the whole back catalogue jump when an editor toggles a checkbox.
     */
    public function publish(): self
    {
        $this->status = EventStatus::Published;
        $this->published_at ??= CarbonImmutable::now();
        $this->save();

        return $this;
    }

    public function unpublish(): self
    {
        $this->status = EventStatus::Draft;
        $this->save();

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->status === EventStatus::Published;
    }

    /**
     * Publicly readable at all: published *and* not private.
     *
     * Both halves matter. A published private event is still Control-Panel only,
     * and an unpublished public one is still a draft.
     */
    public function isPubliclyReadable(): bool
    {
        return $this->isPublished() && $this->visibility->isAddressable();
    }

    public function isListable(): bool
    {
        return $this->isPublished() && $this->visibility->isListable();
    }

    /**
     * Published, whatever the visibility. A Control-Panel-side scope.
     *
     * Deliberately NOT the default for a frontend query: it includes private
     * events, and the first version of this addon used it as the fallback for
     * `listable="false"` — which put a private workshop on a public page. The
     * suite caught it. Frontend queries use addressable() below.
     */
    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', EventStatus::Published->value);
    }

    /**
     * What a visitor may be shown at all: published and not private.
     *
     * The widest a frontend query is ever allowed to be. Unlisted events are in,
     * because a page that already knows which unlisted event it is showing has to
     * be able to render it; private ones never leave the Control Panel.
     */
    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeAddressable(Builder $query): Builder
    {
        return $query
            ->where('status', EventStatus::Published->value)
            ->where('visibility', '!=', Visibility::Private->value);
    }

    /** Published *and* public: what a calendar feed may contain. */
    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeListable(Builder $query): Builder
    {
        return $query
            ->where('status', EventStatus::Published->value)
            ->where('visibility', Visibility::Public->value);
    }

    /**
     * @param  Builder<Event>  $query
     * @param  string|array<int, string>  $type
     * @return Builder<Event>
     */
    public function scopeOfType(Builder $query, string|array $type): Builder
    {
        return $query->whereIn('type', (array) $type);
    }
}
