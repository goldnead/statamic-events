<?php

namespace Goldnead\Events\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many events were published.
 *
 * On `published_at`, which is set once — at the transition to published, from
 * `Event::booted()` — and not on `created_at`. The question a person asks of a
 * calendar is when something became visible, not when somebody started typing
 * it, and the two can be weeks apart.
 *
 * **A draft has no `published_at` and therefore falls out of the window
 * entirely.** That is the intent and not an accident of the SQL: an event nobody
 * can see is not something that happened. It also means this figure never
 * changes retroactively — a draft that is published next month is counted next
 * month, where the publishing actually took place.
 *
 * Events, not their dates. A concert series announced once with fourteen dates
 * is one publication and fourteen entries in {@see Occurrences}, and adding the
 * two figures together would be adding two different things.
 */
class Published extends EventMetric implements HasBreakdowns
{
    protected function table(): string
    {
        return 'events';
    }

    protected function timestamp(): string
    {
        return 'published_at';
    }

    public function handle(): string
    {
        return 'events.published';
    }

    public function label(): string
    {
        return __('events::cp.metric_published');
    }

    public function description(): ?string
    {
        return __('events::cp.metric_published_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return (int) $this->inPeriod($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->inPeriod($query), $query, 'count(*)'),
        );
    }

    public function breakdowns(): array
    {
        return ['type' => __('events::cp.metric_breakdown_type')];
    }

    /**
     * Split by the event's type.
     *
     * A free string rather than an enum, because the option list in
     * `config/events.php` is only what the Control Panel offers and removing an
     * option there must never orphan the events already carrying it. So the
     * split shows what the rows actually say, including a type the configuration
     * has since forgotten — and including the empty one, which is a row like any
     * other.
     */
    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if ($dimension !== 'type' || ! $this->available()) {
            return [];
        }

        return $this->labelled(
            $this->splitByColumn($this->inPeriod($query), $query, 'type', 'count(*)', $limit),
            'type',
        );
    }
}
