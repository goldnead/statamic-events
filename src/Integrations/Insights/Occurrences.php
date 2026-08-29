<?php

namespace Goldnead\Events\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many dates fall inside the window.
 *
 * On `starts_at`: **when the date happens**, not when somebody entered it. This
 * is the calendar view, and it is the only reading of "how many dates in August"
 * that a person means. Counting on `created_at` instead would answer a question
 * about administrative activity and would put a concert entered in March into
 * March, which is not where it is played.
 *
 * The consequence is worth saying out loud: **the series points into the future
 * whenever the window does.** A window covering the coming quarter draws the
 * dates that are still to be played, and that is the chart a calendar is read
 * for. Every other figure in this family looks backwards; this one does not have
 * to.
 *
 * A cancelled date is never deleted — it keeps its row, its UID and its
 * `status = cancelled`, so that the cancellation reaches everybody who already
 * imported it. It therefore stays in this count, where it belongs: the date is
 * still in the calendar, and the `status` split below is where it says so.
 * {@see Cancelled} counts on a different axis and is not a subset of this
 * figure.
 */
class Occurrences extends EventMetric implements HasBreakdowns
{
    protected function table(): string
    {
        return 'event_occurrences';
    }

    protected function timestamp(): string
    {
        return 'starts_at';
    }

    public function handle(): string
    {
        return 'events.occurrences';
    }

    public function label(): string
    {
        return __('events::cp.metric_occurrences');
    }

    public function description(): ?string
    {
        return __('events::cp.metric_occurrences_description');
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
        return ['status' => __('events::cp.metric_breakdown_status')];
    }

    /**
     * Scheduled against cancelled, over the dates of the window.
     *
     * The split a reader wants beside the count: of the dates that fall in this
     * period, how many are still standing. Both rows are honest about the same
     * window, which a ratio built from two differently dated figures would not
     * be.
     */
    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if ($dimension !== 'status' || ! $this->available()) {
            return [];
        }

        return $this->labelled(
            $this->splitByColumn($this->inPeriod($query), $query, 'status', 'count(*)', $limit),
            'status',
        );
    }
}
