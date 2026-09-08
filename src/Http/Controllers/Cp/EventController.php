<?php

namespace Goldnead\Events\Http\Controllers\Cp;

use Goldnead\Events\Enums\EventStatus;
use Goldnead\Events\Enums\OccurrenceStatus;
use Goldnead\Events\Enums\Visibility;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Events\Query\Scopes\Filters\EventFilter;
use Goldnead\Events\Support\Blueprints;
use Goldnead\Events\Support\Setup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Statamic\CP\Column;
use Statamic\CP\PublishForm;
use Statamic\Facades\Scope;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;

/**
 * Events in the Control Panel.
 *
 * `index()` serves two representations of one query — the Inertia page that
 * boots core's `<Listing>`, and the JSON that listing then fetches on every
 * search, sort, filter and page change. That is core's own arrangement (see
 * FormsController) and it is why there is no second "data" route.
 *
 * The create form is `Statamic\CP\PublishForm`, which renders core's own
 * PublishForm page. Editing has no page of its own: the detail screen carries
 * the same blueprint as an editable publish form, the way a collection entry
 * does. Both paths submit to `store()`/`update()` unchanged.
 *
 * Authorization goes through the Gate on every action, including the read ones.
 * `Gate::authorize()` is used rather than anything read off the authenticated
 * user directly: `hasPermission()`, `isSuper()` and `id()` do not exist on an
 * Eloquent user, and calling them is how a CP screen crashes on exactly the
 * sites that are hardest to reach for a fix.
 */
class EventController extends Controller
{
    use QueriesFilters;

    /** Sorting is whitelisted: `sort` arrives from the query string and lands in an ORDER BY. */
    private const SORTABLE = ['title', 'type', 'status', 'visibility', 'created_at'];

    private const MAX_PER_PAGE = 500;

    public function index(FilteredRequest $request)
    {
        Gate::authorize('view events');

        // Both tables, and before the JSON branch below because that one
        // queries too: the listing counts each event's dates with
        // `withCount('occurrences')`, so `event_occurrences` is touched while
        // the page is built even though no date is shown on its own row.
        if ($setup = Setup::guard(__('events::cp.title'), 'events', 'event_occurrences')) {
            return $setup;
        }

        if ($request->wantsJson()) {
            return $this->listing($request);
        }

        return Inertia::render('events::Events/Index', [
            'initialColumns' => collect($this->columns())->map->toArray()->all(),
            'filters' => Scope::filters(EventFilter::LISTING_KEY),
            'hasAny' => Event::query()->exists(),
            'listingUrl' => cp_route('events.index'),
            'createUrl' => cp_route('events.create'),
            'canCreate' => Gate::allows('manage events'),
            'perPage' => $this->perPage(null),
        ]);
    }

    public function create()
    {
        Gate::authorize('manage events');

        return PublishForm::make(Blueprints::event())
            ->title(__('events::cp.create_event'))
            ->icon('calendar')
            ->values([
                'type' => array_key_first(Blueprints::typeOptions()) ?: 'other',
                'status' => EventStatus::Draft->value,
                'visibility' => (string) config('events.defaults.visibility', Visibility::Public->value),
                'timezone' => Event::defaultTimezone(),
            ])
            ->submittingTo(cp_route('events.store'), 'POST');
    }

    public function store(FilteredRequest $request)
    {
        Gate::authorize('manage events');

        $values = PublishForm::make(Blueprints::event())->submit($request->all());

        $event = Event::create($this->attributes($values));

        // `redirect` is the key core's PublishForm reads off the save response
        // (ui/Publish/Form.vue). Returning a 302 instead makes the XHR follow it
        // with the original verb and re-enter this action until the browser gives
        // up with ERR_TOO_MANY_REDIRECTS.
        return [
            'saved' => true,
            'redirect' => cp_route('events.show', ['event' => $event->getKey()]),
        ];
    }

    public function show(FilteredRequest $request, int $event)
    {
        Gate::authorize('view events');

        $model = Event::query()->with('occurrences')->findOrFail($event);

        $canManage = Gate::allows('manage events');

        return Inertia::render('events::Events/Show', [
            'event' => [
                'id' => $model->getKey(),
                'title' => $model->title,
            ],
            // The detail screen *is* the form, the way a collection entry's is.
            // What used to sit here as read-only key/value pills, with a
            // "Bearbeiten" button leading to a second screen, is now core's own
            // publish form on this page: same blueprint, same fields, same save
            // endpoint. The three keys are exactly what `Statamic\CP\PublishForm`
            // hands its Vue page, so the screen renders what core would render.
            'form' => $this->formPayload($model),
            'occurrences' => $model->occurrences->map(fn ($occurrence) => $this->occurrenceRow($occurrence))->values()->all(),
            'occurrenceColumns' => collect($this->occurrenceColumns())->map->toArray()->all(),
            // Row and bulk actions both post here. It is handed over even to a
            // read-only user; the page decides whether to wire it up, and every
            // action authorizes its own items regardless.
            'occurrenceActionUrl' => cp_route('events.occurrences.actions.run'),
            'deleteUrl' => cp_route('events.destroy', ['event' => $model->getKey()]),
            'indexUrl' => cp_route('events.index'),
            'addOccurrenceUrl' => cp_route('events.occurrences.create', ['event' => $model->getKey()]),
            'feedUrl' => route('statamic.events.feed'),
            'canManage' => $canManage,
        ]);
    }

    /**
     * The publish form for one event, in the shape core's own PublishForm page
     * receives it.
     *
     * Built here rather than by returning a `Statamic\CP\PublishForm`, because
     * this form shares its screen with the dates table: the response is one
     * Inertia page of this addon's, and the form is a part of it. The three
     * lines below are what `PublishForm::toResponse()` does, and nothing more —
     * the rendering, the tabs, the sidebar and the save pipeline all stay core's.
     *
     * @return array<string, mixed>
     */
    private function formPayload(Event $model): array
    {
        $blueprint = Blueprints::event($model->type);

        $fields = $blueprint->fields()->addValues([
            'title' => $model->title,
            'slug' => $model->slug,
            'description' => $model->description,
            'type' => $model->type,
            'status' => $model->status->value,
            'visibility' => $model->visibility->value,
            'timezone' => $model->timezone,
        ])->preProcess();

        return [
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
            'submitUrl' => cp_route('events.update', ['event' => $model->getKey()]),
        ];
    }

    public function update(FilteredRequest $request, int $event)
    {
        Gate::authorize('manage events');

        $model = Event::query()->findOrFail($event);

        $values = PublishForm::make(Blueprints::event($model->type))->submit($request->all());

        // Read *before* fill(), which is what makes this a transition test rather
        // than a tautology: after filling, `status` already holds what the form
        // asked for, so `isPublished()` answers yes and publish() — the only
        // place that stamps published_at — never runs.
        $wasPublished = $model->isPublished();

        $model->fill($this->attributes($values));

        $wantsPublished = ($values['status'] ?? null) === EventStatus::Published->value;

        $wantsPublished && ! $wasPublished
            ? $model->publish()
            : $model->save();

        return ['saved' => true];
    }

    public function destroy(int $event)
    {
        Gate::authorize('manage events');

        Event::query()->findOrFail($event)->delete();

        return ['redirect' => cp_route('events.index')];
    }

    /**
     * Maps submitted blueprint values onto columns.
     *
     * Explicit rather than a mass assign of `$values`: the model is `$guarded =
     * []`, so anything the blueprint happens to carry would otherwise reach a
     * column — including `brand_id`, which decides who can see the row.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function attributes(array $values): array
    {
        return [
            'title' => $values['title'] ?? '',
            'slug' => $values['slug'] ?? null,
            'description' => $values['description'] ?? null,
            'type' => $values['type'] ?? 'other',
            'status' => $values['status'] ?? EventStatus::Draft->value,
            'visibility' => $values['visibility'] ?? Visibility::Public->value,
            // The dictionary fieldtype hands back a single value or a one-element
            // list depending on `max_items`; normalise rather than trust either.
            'timezone' => $this->single($values['timezone'] ?? null) ?: Event::defaultTimezone(),
        ];
    }

    private function single(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return filled($value) ? (string) $value : null;
    }

    /** The <Listing> response contract: `data` plus `meta` carrying columns on every page. */
    private function listing(FilteredRequest $request): array
    {
        $query = Event::query()->withCount('occurrences');

        $badges = $this->queryFilters(
            $query,
            $request->filters ?? [],
            ['handle' => EventFilter::LISTING_KEY],
        );

        $this->applySearch($query, (string) $request->input('search', ''));

        $sort = in_array($request->input('sort'), self::SORTABLE, true)
            ? $request->input('sort')
            : 'title';

        $order = $request->input('order') === 'desc' ? 'desc' : 'asc';

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderBy($sort, $order)
            ->orderBy('id', $order)
            ->paginate($this->perPage($request->input('perPage')))
            ->withQueryString();

        return [
            'data' => collect($paginator->items())->map(fn (Event $event) => $this->row($event))->all(),
            'meta' => [
                'columns' => collect($this->columns())->map->toArray()->all(),
                'activeFilterBadges' => $badges,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function applySearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        // Escaped and anchored as a prefix, so the index stays usable and a `%`
        // typed by a user is a percent sign rather than a full table scan.
        $term = str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        $query->where(function ($query) use ($term): void {
            $query->where('title', 'like', $term)->orWhere('slug', 'like', $term);
        });
    }

    private function perPage(mixed $requested): int
    {
        $default = (int) config('events.cp.per_page', 50);
        $perPage = (int) ($requested ?: $default);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }

    /** @return array<int, Column> */
    private function columns(): array
    {
        return [
            Column::make('title')->label(__('events::cp.col_title')),
            Column::make('type')->label(__('events::cp.col_type')),
            Column::make('next_occurrence')->label(__('events::cp.col_next'))->sortable(false),
            Column::make('occurrences_count')->label(__('events::cp.col_dates'))->sortable(false)->numeric(true),
            Column::make('status')->label(__('events::cp.col_status')),
            Column::make('visibility')->label(__('events::cp.col_visibility')),
        ];
    }

    /**
     * The columns of the dates listing on an event's screen.
     *
     * Same helper as the index screen above, so the two tables cannot drift
     * apart: core's `Column` is what carries the label, the sortable flag and
     * the visibility into `<Listing>`. That listing runs in its client-side
     * mode — an event's dates arrive complete as an Inertia prop, so there is
     * nothing to page or re-fetch and no second route to build.
     *
     * @return array<int, Column>
     */
    private function occurrenceColumns(): array
    {
        return [
            Column::make('starts_at')->label(__('events::cp.col_period')),
            Column::make('timezone')->label(__('events::cp.col_timezone')),
            Column::make('location')->label(__('events::cp.col_location')),
            Column::make('status')->label(__('events::cp.col_status')),
        ];
    }

    /**
     * One row of that listing.
     *
     * The split between `starts_at` and `period_label` is what makes the column
     * sort correctly: client-side sorting compares the raw field, and a value
     * like "Tue, 15 Sep 2026" sorts alphabetically by weekday. The sortable
     * value is therefore ISO and the readable one travels beside it — the same
     * arrangement `status` and `status_label` already use on the index.
     *
     * @return array<string, mixed>
     */
    private function occurrenceRow(Occurrence $occurrence): array
    {
        return [
            'id' => $occurrence->getKey(),
            // Rendered in the date's own zone, never the viewer's: a date belongs
            // at its place. The zone is a column of its own so nobody has to
            // guess which one they are reading.
            'starts_at' => $occurrence->localStart()->format('Y-m-d H:i'),
            // No `ends_at` beside it: the end is part of `period_label` and no
            // column or slot reads it on its own.
            'period_label' => $this->period($occurrence),
            'timezone' => $occurrence->effectiveTimezone(),
            'all_day' => $occurrence->all_day,
            'status' => $occurrence->status->value,
            'status_label' => OccurrenceStatus::options()[$occurrence->status->value] ?? $occurrence->status->value,
            'cancelled' => $occurrence->isCancelled(),
            'location' => $occurrence->locationLine(),
            'online' => $occurrence->isOnline(),
            'ics_url' => route('statamic.events.occurrence', ['uuid' => $occurrence->uuid]),
            'edit_url' => cp_route('events.occurrences.edit', ['occurrence' => $occurrence->getKey()]),
        ];
    }

    /**
     * The readable window.
     *
     * The end repeats the date only when it falls on another day. A two-hour
     * evening reads as "Tue, 15 Sep 2026 19:00 – 21:30" rather than printing
     * the same date twice, which in a table column costs exactly the width the
     * location needs.
     */
    private function period(Occurrence $occurrence): string
    {
        $format = $occurrence->all_day ? 'D, d M Y' : 'D, d M Y H:i';

        $start = $occurrence->localStart();
        $end = $occurrence->localEnd();

        if (! $end || ($occurrence->all_day && $end->isSameDay($start))) {
            return $start->format($format);
        }

        return $start->format($format).' – '.$end->format($end->isSameDay($start) ? 'H:i' : $format);
    }

    /** @return array<string, mixed> */
    private function row(Event $event): array
    {
        $next = $event->occurrences()->scheduled()->upcoming()->first();

        return [
            'id' => $event->getKey(),
            'title' => $event->title,
            'type' => Blueprints::typeOptions($event->type)[$event->type] ?? $event->type,
            'next_occurrence' => $next?->localStart()->format('d M Y H:i'),
            'next_timezone' => $next?->effectiveTimezone(),
            'occurrences_count' => (int) ($event->occurrences_count ?? 0),
            'status' => $event->status->value,
            'status_label' => EventStatus::options()[$event->status->value] ?? $event->status->value,
            'visibility' => $event->visibility->value,
            'visibility_label' => Visibility::options()[$event->visibility->value] ?? $event->visibility->value,
            // No second URL for editing: the detail screen is the form.
            'show_url' => cp_route('events.show', ['event' => $event->getKey()]),
        ];
    }
}
