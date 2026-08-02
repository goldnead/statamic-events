<?php

namespace Goldnead\Events\Http\Controllers\Cp;

use Carbon\CarbonImmutable;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Events\Support\Blueprints;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Statamic\CP\PublishForm;

/**
 * Dates in the Control Panel.
 *
 * Every action here is a write, so every action authorizes. The read-only view
 * of an event's dates lives on the event's own screen — there is no occurrence
 * listing of its own, because a date without its event is not a thing anyone
 * looks for.
 */
class OccurrenceController extends Controller
{
    public function create(int $event)
    {
        Gate::authorize('manage events');

        $model = Event::query()->findOrFail($event);

        return PublishForm::make(Blueprints::occurrence($model))
            ->title(__('events::cp.add_date', ['event' => $model->title]))
            ->icon('calendar')
            ->values($this->defaults($model))
            ->submittingTo(cp_route('events.occurrences.store', ['event' => $model->getKey()]), 'POST');
    }

    public function store(Request $request, int $event)
    {
        Gate::authorize('manage events');

        $model = Event::query()->findOrFail($event);

        $values = PublishForm::make(Blueprints::occurrence($model))->submit($request->all());

        $attributes = $this->attributes($values);
        $this->assertUsable($attributes);

        $model->occurrences()->create($attributes);

        return [
            'saved' => true,
            'redirect' => cp_route('events.show', ['event' => $model->getKey()]),
        ];
    }

    public function edit(int $occurrence)
    {
        Gate::authorize('manage events');

        $model = $this->find($occurrence);

        return PublishForm::make(Blueprints::occurrence($model->event, $model->effectiveTimezone()))
            ->title($model->event->title)
            ->icon('calendar')
            ->values([
                'starts_at' => $this->toFieldValue($model->starts_at),
                'ends_at' => $this->toFieldValue($model->ends_at),
                'all_day' => (bool) $model->all_day,
                'timezone' => $model->timezone,
                'status' => $model->status->value,
                'venue_name' => $model->venue_name,
                'venue_address' => $model->venue_address,
                'venue_city' => $model->venue_city,
                'venue_country' => $model->venue_country,
                'online_url' => $model->online_url,
            ])
            ->submittingTo(cp_route('events.occurrences.update', ['occurrence' => $model->getKey()]));
    }

    public function update(Request $request, int $occurrence)
    {
        Gate::authorize('manage events');

        $model = $this->find($occurrence);

        $values = PublishForm::make(Blueprints::occurrence($model->event, $model->effectiveTimezone()))
            ->submit($request->all());

        $attributes = $this->attributes($values);
        $this->assertUsable($attributes);

        $newStart = $attributes['starts_at'];
        $newEnd = $attributes['ends_at'];

        // Everything that is not the window is a plain edit. The window goes
        // through reschedule(), which is the one place that bumps SEQUENCE and
        // emits OccurrenceRescheduled — a calendar client that already holds this
        // UID ignores a new version that does not raise the sequence.
        unset($attributes['starts_at'], $attributes['ends_at']);

        $model->fill($attributes)->save();
        $model->reschedule($newStart, $newEnd);

        return ['saved' => true];
    }

    /**
     * Cancels a date. Idempotent — the model no-ops on an already cancelled one,
     * so a double click does not emit a second cancellation.
     */
    public function cancel(Request $request, int $occurrence)
    {
        Gate::authorize('manage events');

        $model = $this->find($occurrence);

        $model->cancel($request->string('reason')->limit(255, '')->toString() ?: null);

        return back()->with('success', __('events::cp.occurrence_cancelled_flash'));
    }

    public function destroy(int $occurrence)
    {
        Gate::authorize('manage events');

        $this->find($occurrence)->delete();

        return back()->with('success', __('events::cp.occurrence_deleted_flash'));
    }

    /**
     * The two model-level invariants, checked here so they surface as field
     * errors on the form the editor is looking at.
     *
     * The model still enforces both — this is the friendly path, not the
     * authority. Dropping the model guard and trusting the controller is how an
     * invariant survives exactly as long as nobody writes a second write path.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertUsable(array $attributes): void
    {
        if (blank($attributes['venue_name']) && blank($attributes['online_url'])) {
            throw ValidationException::withMessages([
                'venue_name' => __('events::cp.validation_needs_location'),
            ]);
        }

        $start = $attributes['starts_at'];
        $end = $attributes['ends_at'];

        if ($end instanceof CarbonImmutable && $end->lessThan($start)) {
            throw ValidationException::withMessages([
                'ends_at' => __('events::cp.validation_ends_before_starts'),
            ]);
        }
    }

    /**
     * The brand scope is what keeps an operator in brand A from reading a brand B
     * row by guessing its id, so the lookup deliberately goes through the model's
     * default query rather than around it.
     */
    private function find(int $id): Occurrence
    {
        return Occurrence::query()->with('event')->findOrFail($id);
    }

    /**
     * Sensible starting values for a new date: the previous date's location, so
     * a ten-date series is not ten identical address entries.
     *
     * @return array<string, mixed>
     */
    private function defaults(Event $event): array
    {
        $previous = $event->occurrences()->orderByDesc('starts_at')->first();

        return [
            'all_day' => false,
            'venue_name' => $previous?->venue_name,
            'venue_address' => $previous?->venue_address,
            'venue_city' => $previous?->venue_city,
            'venue_country' => $previous?->venue_country,
            'online_url' => $previous?->online_url,
        ];
    }

    /**
     * Blueprint values → columns.
     *
     * Statamic's date fieldtype hands back a wall-clock string in
     * `config('app.timezone')` (Date::processDateTime()), whatever zone the
     * widget displayed. Parsing it in that zone and storing UTC is therefore the
     * exact inverse of toFieldValue() below, and the round trip does not depend
     * on which zone the editor was shown.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function attributes(array $values): array
    {
        return [
            'starts_at' => $this->fromFieldValue($values['starts_at'] ?? null) ?? CarbonImmutable::now()->utc(),
            'ends_at' => $this->fromFieldValue($values['ends_at'] ?? null),
            'all_day' => (bool) ($values['all_day'] ?? false),
            'timezone' => $this->single($values['timezone'] ?? null),
            'venue_name' => $values['venue_name'] ?? null,
            'venue_address' => $values['venue_address'] ?? null,
            'venue_city' => $values['venue_city'] ?? null,
            'venue_country' => $this->single($values['venue_country'] ?? null),
            'online_url' => $values['online_url'] ?? null,
        ];
    }

    private function fromFieldValue(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        return CarbonImmutable::parse((string) $value, config('app.timezone'))->utc();
    }

    private function toFieldValue(?CarbonImmutable $value): ?string
    {
        return $value?->setTimezone(config('app.timezone'))->format('Y-m-d H:i');
    }

    private function single(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return filled($value) ? (string) $value : null;
    }
}
