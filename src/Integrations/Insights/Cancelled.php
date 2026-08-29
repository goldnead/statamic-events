<?php

namespace Goldnead\Events\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many dates were called off.
 *
 * On `cancelled_at`: **when the cancellation happened**, not when the date would
 * have been. A concert in December called off in August belongs to August,
 * because August is when the news went out to everybody holding a ticket — the
 * same rule the sibling addons apply to a refund, and for the same reason.
 *
 * **This is therefore not a subset of {@see Occurrences} over the same window.**
 * The two count on different axes: one on when a date is played, the other on
 * when it was withdrawn, and in any given month either may contain rows the
 * other does not.
 *
 * Which is why there is **no cancellation rate here**. Dividing these two
 * figures would produce a percentage whose numerator and denominator are about
 * different dates and which can exceed a hundred — a number that looks precise
 * and states nothing. The honest version of that question is the `status` split
 * on {@see Occurrences}, where both parts are windowed the same way.
 *
 * A cancelled date keeps its row. Nothing is deleted, so this count is stable
 * once written and cannot shrink later.
 */
class Cancelled extends EventMetric
{
    protected function table(): string
    {
        return 'event_occurrences';
    }

    protected function timestamp(): string
    {
        return 'cancelled_at';
    }

    public function handle(): string
    {
        return 'events.cancelled';
    }

    public function label(): string
    {
        return __('events::cp.metric_cancelled');
    }

    public function description(): ?string
    {
        return __('events::cp.metric_cancelled_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    /**
     * A date that was never cancelled has no `cancelled_at` and is excluded by
     * the window itself — there is no status condition here, and none is needed.
     */
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
}
