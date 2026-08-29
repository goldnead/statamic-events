<?php

namespace Goldnead\Events\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\TableMetric;
use Illuminate\Database\Query\Builder;

/**
 * What every figure this addon offers the analytics addon has in common.
 *
 * The counting itself is inherited: {@see TableMetric} already windows a period,
 * buckets a timestamp in three SQL dialects and splits by a column without
 * dropping the rows whose value is null. What is added here is the part that is
 * this addon's own and that no shared base could know — the timezone its columns
 * are stored in, and the brand its rows belong to.
 *
 * Nothing in this directory is loaded unless the sibling has announced itself:
 * the classes name its contract in their `extends` and their type hints, and the
 * `class_exists` guard in the ServiceProvider is what keeps PHP from ever
 * reaching the file otherwise. Hence `suggest` in composer.json and never
 * `require` — a calendar on an artist's website installs without a dashboard.
 *
 * ## Every instant is compared in UTC
 *
 * `published_at`, `starts_at` and `cancelled_at` are stored as UTC and read
 * through `UtcDateTime` accessors — that is the addon's oldest rule, and the
 * reason a date belongs at its own place rather than at the viewer's.
 *
 * The period, however, arrives from a screen that built it with `Carbon::now()`
 * in the **application's** timezone. Handed to the query builder as it is, a
 * boundary of "1. August, 00:00" in Europe/Berlin is written into the SQL as the
 * literal `2026-08-01 00:00:00` and compared against UTC data — an instant two
 * hours away from the one the reader asked for. Every window would be shifted by
 * the offset, silently, and a report is wrong in exactly the way nobody checks.
 *
 * So the bounds are moved to UTC before they are compared. The *instant* does
 * not change; only the way it is written down does, and it ends up written down
 * the way the column is. The buckets follow from the same column and are
 * therefore UTC days, which is the only grain that keeps a chart's columns and
 * its total in agreement.
 *
 * ## Brands are context, not a filter
 *
 * Every model here carries `brand_id` and reads through `BrandScope`, which in
 * multi-brand mode filters to the current brand and — with no current brand —
 * **fails closed**, returning nothing rather than everything. That posture is
 * the point of the package: nothing leaks across brands.
 *
 * These metrics read with the query builder, which no global scope ever sees. So
 * the condition has to be applied deliberately, as an exact mirror of
 * `BrandScope::apply()`: a no-op in single-brand mode, the current brand in
 * multi-brand mode, and `1 = 0` when there is no current brand and the
 * configured fail mode is closed. A dashboard that counted across all brands
 * would be the one screen in the installation that shows a reader another
 * brand's numbers.
 *
 * That mirror used to be written out here, line for line. It now lives in
 * `TableMetric::brandScoped()`, where every addon of the family reads it from
 * one copy, and all this class does is name its column — see
 * {@see brandColumn()}. The transcription was correct here and wrong or missing
 * in the siblings, which is the argument for one copy rather than seven: on the
 * demo, the Invoices group showed the money of three other brands beside a
 * correctly empty CRM, and nothing on the screen said which tile to believe.
 *
 * **No `brand` filter is offered, and that is a decision rather than an
 * omission.** A brand is resolved per request by brand-context — from the
 * domain, the user's membership, the session — and it is a context, not a
 * report setting. A filter that let a URL parameter name the brand would be the
 * same leak with an extra step, and one that was offered and then quietly
 * ignored would be a switch that leads nowhere. Somebody who wants another
 * brand's figures switches brand, and every screen follows.
 */
abstract class EventMetric extends TableMetric
{
    public function group(): string
    {
        return __('events::cp.metric_group');
    }

    /**
     * Every row of this addon carries the brand it belongs to.
     *
     * Naming the column is the whole of what this addon has to do about brands:
     * {@see TableMetric::inPeriod()} then applies `BrandScope`'s own rules to
     * the figure, the chart and every split at once, and no single metric can
     * forget to. Both tables have the column — `events` from its own migration,
     * `event_occurrences` from its, kept equal to the parent event's by
     * `Occurrence::creating()` — so the same declaration serves all three
     * metrics, whichever table they count on.
     *
     * **No `brand` filter is offered along with it, and that is a decision.** A
     * brand is resolved per request from the domain, the user's membership or
     * the session; it is context, not a report setting. A filter that let a URL
     * parameter name the brand would be the leak with an extra step.
     */
    protected function brandColumn(): ?string
    {
        return 'brand_id';
    }

    /**
     * The rows inside the window: dated, in UTC, and in the reader's brand.
     *
     * The UTC conversion is what this override is for. The dating and the brand
     * both come from the base class — the one through the period it is handed,
     * the other through {@see brandColumn()} above — so a figure, its chart and
     * every split of it are windowed and scoped identically and none of the
     * three can be forgotten on its own.
     *
     * ## A row with no timestamp is in no period, "all time" included
     *
     * `TableMetric::inPeriod()` windows through two `when()` clauses on the
     * period bounds. Over an open-ended period — the `all` preset — both bounds
     * are null, so neither clause is applied and no date condition survives. On
     * a nullable timestamp column that is not "everything since the beginning",
     * it is literally every row in the table.
     *
     * Both of this addon's optional timestamps are nullable, and each fails in
     * its own way. `published_at` is null on every draft, so "all time" would
     * count the drafts this metric exists to exclude. `cancelled_at` is null on
     * every date that was *not* called off, so over all time `events.cancelled`
     * would report every date in the calendar as a cancellation — the largest
     * possible wrong answer, on the one screen nobody re-adds by hand.
     *
     * The series has a second symptom: `strftime('%Y-%m-%d', NULL)` is NULL, so
     * the undated rows collect in a bucket with no key and the chart grows a
     * column the axis cannot name.
     *
     * The guard against all of that is `whereNotNull` in the base class's own
     * `inPeriod()`, put there after this addon found the defect. It is not
     * repeated here: a second copy of a condition is a second thing to keep in
     * step, and `InsightsContractsMatchTest` is what holds the base class to
     * still carrying it.
     */
    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        return parent::inPeriod($this->readInUtc($query), $column);
    }

    /**
     * The same question, with its bounds written down in UTC.
     *
     * A new value object rather than a mutation: `Period` is readonly by design,
     * because two places that each parse a date are two places that can disagree
     * by a day.
     *
     * An open-ended period has no bounds to convert and is handed back
     * untouched — there is nothing to be wrong about.
     */
    protected function readInUtc(MetricQuery $query): MetricQuery
    {
        $period = $query->period;

        if ($period->from === null || $period->to === null) {
            return $query;
        }

        return new MetricQuery(
            Period::between($period->from->copy()->utc(), $period->to->copy()->utc()),
            $query->bucket,
            $query->filters,
        );
    }

    /**
     * What to call the rows that have no value in the dimension they are split by.
     *
     * Per dimension, because "no type" and "no status" read differently and a
     * shared dash tells a reader nothing about which of the two they are looking
     * at.
     */
    protected function missingLabel(string $dimension): string
    {
        return __('events::cp.metric_no_'.$dimension);
    }
}
