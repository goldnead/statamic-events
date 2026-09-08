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
use Statamic\Facades\User;

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
            // **Ohne den Benutzer**, und das ist der Unterschied zwischen
            // einem Formular-Default und einem harten Rueckfall.
            //
            // Dieser Haken feuert auf **jedem** Weg, der eine Zeile anlegt:
            // Seeder, Import, Queue-Job, ein Kommando, das zufaellig in einem
            // angemeldeten Kontext laeuft. Faende er hier die persoenliche
            // Zeitzone des gerade angemeldeten Benutzers, bekaeme jede
            // importierte Zeile sie aufgestempelt — und zwei gleiche
            // Importlaeufe zweier Admins erzeugten verschiedene Ergebnisse,
            // ohne Meldung, sichtbar erst als falsch angezeigte Startzeit.
            //
            // Die Vorbelegung aus der Zeitzone des Menschen gehoert dorthin,
            // wo ein Mensch ein Formular ausfuellt, und dort steht sie auch
            // ({@see \Goldnead\Events\Http\Controllers\Cp\EventController}).
            $event->timezone = $event->timezone ?: static::configuredTimezone();
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

    /**
     * Die Zeitzone, mit der ein neuer Termin startet.
     *
     * Vier Quellen, von der naechsten zur fernsten. Nur eine Vorbelegung: das
     * Feld bleibt aenderbar, und bestehende Termine fasst niemand an.
     *
     * 1. **Die des angemeldeten Benutzers**, falls der Betrieb seinem
     *    Benutzer-Blueprint ein Feld `timezone` gegeben hat. Statamic fuehrt
     *    von sich aus keine Zeitzone je Benutzer — es gibt keine Eigenschaft
     *    dafuer und keine Voreinstellung im Kern —, also ist das hier eine
     *    Einladung und keine Annahme: wer das Feld anlegt, wird gelesen, wer
     *    nicht, merkt nichts.
     * 2. **Die des Addons** (`events.defaults.timezone`), weil sie jemand
     *    ausdruecklich fuer Termine gesetzt hat.
     * 3. **Die der Seite** (`statamic.system.display_timezone`), die Statamic
     *    fuer die Anzeige von Daten im Frontend benutzt. Das ist die Zeitzone,
     *    in der die Seite ohnehin ueber Zeiten spricht.
     * 4. `app.timezone`, sonst UTC.
     *
     * Der Wert des Benutzers wird geprueft, nicht geglaubt: in einem freien
     * Textfeld steht irgendwann „MEZ", und eine ungueltige Zeitzone in einem
     * Pflichtfeld ist ein Formular, das sich nicht abschicken laesst, ohne zu
     * sagen warum.
     */
    public static function defaultTimezone(): string
    {
        return self::timezoneOfCurrentUser() ?: self::configuredTimezone();
    }

    /**
     * Dasselbe ohne die Person: die drei Quellen, die auf jedem Weg dieselbe
     * Antwort geben.
     *
     * Getrennt, weil die beiden Fragen verschieden sind. „Womit fuellt sich
     * dieses Formular vor" darf die Person kennen; „was steht in dieser Zeile,
     * wenn niemand etwas gesagt hat" darf es nicht, sonst haengt ein
     * gespeicherter Wert daran, wer zufaellig angemeldet war.
     */
    protected static function configuredTimezone(): string
    {
        return config('events.defaults.timezone')
            ?: config('statamic.system.display_timezone')
            ?: config('app.timezone')
            ?: 'UTC';
    }

    protected static function timezoneOfCurrentUser(): ?string
    {
        $user = User::current();

        // Gefragt wird die Methode, nicht der Typ. `get()` steht auf jeder
        // Benutzer-Klasse, die Statamic mitbringt, aber nicht auf dem
        // Interface — und ein Betrieb darf seine eigene einsetzen. Dieselbe
        // Pruefung, die die Katalog-Bruecke nebenan fuer ihr Geschwister macht.
        if (! $user || ! method_exists($user, 'get')) {
            return null;
        }

        $zone = $user->get('timezone');

        if (! is_string($zone) || trim($zone) === '') {
            return null;
        }

        $zone = trim($zone);

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : null;
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
