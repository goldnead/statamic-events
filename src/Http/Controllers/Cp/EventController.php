<?php

namespace Goldnead\Events\Http\Controllers\Cp;

use Goldnead\Events\Enums\EventStatus;
use Goldnead\Events\Enums\Visibility;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Query\Scopes\Filters\EventFilter;
use Goldnead\Events\Support\Blueprints;
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
 * The create/edit forms are `Statamic\CP\PublishForm`, which renders core's own
 * PublishForm page. No Vue is written for them at all, so they cannot drift away
 * from what a Statamic publish form looks like.
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
                'slug' => $model->slug,
                'type' => Blueprints::typeOptions($model->type)[$model->type] ?? $model->type,
                'status' => $model->status->value,
                'statusLabel' => EventStatus::options()[$model->status->value] ?? $model->status->value,
                'visibility' => $model->visibility->value,
                'visibilityLabel' => Visibility::options()[$model->visibility->value] ?? $model->visibility->value,
                'timezone' => $model->timezone,
                'description' => $model->description,
            ],
            'occurrences' => $model->occurrences->map(fn ($occurrence) => [
                'id' => $occurrence->getKey(),
                // Rendered in the date's own zone, never the viewer's: a date
                // belongs at its place. The zone is shown alongside so nobody has
                // to guess which one they are reading.
                'starts_at' => $occurrence->localStart()->format($occurrence->all_day ? 'D, d M Y' : 'D, d M Y H:i'),
                'ends_at' => $occurrence->localEnd()?->format($occurrence->all_day ? 'D, d M Y' : 'D, d M Y H:i'),
                'timezone' => $occurrence->effectiveTimezone(),
                'all_day' => $occurrence->all_day,
                'status' => $occurrence->status->value,
                'cancelled' => $occurrence->isCancelled(),
                'location' => $occurrence->locationLine(),
                'online' => $occurrence->isOnline(),
                'ics_url' => route('statamic.events.occurrence', ['uuid' => $occurrence->uuid]),
                'edit_url' => cp_route('events.occurrences.edit', ['occurrence' => $occurrence->getKey()]),
                'cancel_url' => cp_route('events.occurrences.cancel', ['occurrence' => $occurrence->getKey()]),
                'delete_url' => cp_route('events.occurrences.destroy', ['occurrence' => $occurrence->getKey()]),
            ])->values()->all(),
            'editUrl' => cp_route('events.edit', ['event' => $model->getKey()]),
            'deleteUrl' => cp_route('events.destroy', ['event' => $model->getKey()]),
            'indexUrl' => cp_route('events.index'),
            'addOccurrenceUrl' => cp_route('events.occurrences.create', ['event' => $model->getKey()]),
            'feedUrl' => route('statamic.events.feed'),
            'canManage' => $canManage,
        ]);
    }

    public function edit(int $event)
    {
        Gate::authorize('manage events');

        $model = Event::query()->findOrFail($event);

        return PublishForm::make(Blueprints::event($model->type))
            ->title($model->title)
            ->icon('calendar')
            ->values([
                'title' => $model->title,
                'slug' => $model->slug,
                'description' => $model->description,
                'type' => $model->type,
                'status' => $model->status->value,
                'visibility' => $model->visibility->value,
                'timezone' => $model->timezone,
            ])
            ->submittingTo(cp_route('events.update', ['event' => $model->getKey()]));
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
            'show_url' => cp_route('events.show', ['event' => $event->getKey()]),
            'edit_url' => cp_route('events.edit', ['event' => $event->getKey()]),
        ];
    }
}
