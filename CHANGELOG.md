# Changelog

All notable changes to `goldnead/statamic-events` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this package adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Tag names, Antlers tag parameters,
config keys and facade methods are part of the public API from the first release.

## [Unreleased]

### Changed: an event's dates are a Statamic table now

The dates on an event's screen were a stack of blocks with the actions written out as text links
underneath each one. Five dates filled the panel, nothing could be sorted, nothing could be picked,
and it did not look like any other screen in the Control Panel. They are core's `<Listing>` now, in
its client-side mode: the dates arrive complete as an Inertia prop, so there is no new route and
nothing to page through.

Four columns — the window, the timezone, the location and the status — with sortable headers, a
checkbox column and a `…` menu per row. Nothing that was on screen before has gone: the all-day
badge sits beside the window, the timezone and the location are columns of their own, and a
cancelled date says so in the status column instead of a red pill in the corner.

The window no longer repeats the date on the end of a same-day event. `Tue, 15 Sep 2026 19:00 –
21:30`, not the same date twice, which in a column costs exactly the width the location needs.

### Changed: cancelling and deleting a date are registered actions

They were two buttons per row, each with a hand-built confirmation modal, and they only ever worked
on one date. They are `Statamic\Actions\Action` classes now, which is what earns the listing its
checkbox column: the same action runs from a single row's `…` menu and from the bulk bar over a
whole selection, through `POST cp/events/occurrences/actions`, with core's confirmation, core's
toast and core's authorization.

**Removed with them:** `POST cp/events/occurrences/{occurrence}/cancel` and
`DELETE cp/events/occurrences/{occurrence}`, along with `OccurrenceController::cancel()` and
`::destroy()`. They existed to serve the buttons and had no caller left. Control Panel routes are
not part of this package's public API (see the note at the top); the Antlers tags, the config keys
and the facade are untouched.

An id a selection carries that the brand scope does not reach is now a 404 rather than a shrunken
selection. Core decides which actions apply by comparing counts, so an empty collection satisfies
*every* action registered in the Control Panel — verified in the playground, where one foreign-brand
id came back with core's asset actions and a 500.

### Fixed: the event screen no longer borrows another addon's stylesheet

The two panels sat side by side through a pair of responsive grid classes that Statamic core does
not emit. They came from `statamic-marketing` and `statamic-clientrooms`, so the layout only ever
appeared on an installation that happened to carry one of them; on its own this addon fell back to a
stacked page. The panels stack on purpose now, which also gives the table the width it needs.

## [2.1.1] — 2026-09-03

### Fixed: delete moved out of the body and into the page header

The delete button sat in the middle of the page body under the calendar feed: hard to find and
easy to hit by accident. It is now a `DropdownItem variant="destructive"` in the header's `…`
menu, which is where core puts a destructive page action.

Three icon names that do not exist: `book-open-cover`, plus `globe` and `map-pin` inside a bound
expression, where no literal grep finds them. An unknown name renders an empty box and warns
about nothing.

## [2.1.0] — 2026-08-29

### Added: this addon's figures appear in Insights

From 1.1.0 `statamic-insights` is no longer a revenue report but the family's reporting layer: an
addon registers what it can count and gets the period, the comparison against the period before,
the chart, the breakdowns and two finished screens in return.

The coupling is optional in **both** directions. Without Insights nothing here is missing; without
this addon only its own group is missing over there. `suggest`, never `require`.

Every figure follows the contract's house rules: **null is not zero** (a rate with no denominator
has no answer and does not print 0 %), `available()` decides existence and never the data, gaps in
a series are filled by Insights rather than by the metric, and a filter a metric does not
understand is ignored rather than fatal.

Three figures: published events, occurrences, cancelled occurrences.

**The occurrences are deliberately not clamped to now.** Insights' clamp is for figures answering
what *has happened*; here next month is the point of the question, and a screen that hid it would be
lying by omission.

### Fixed: under Octane the metrics registered on the first request only

The registration flag on the service provider was static. A static flag survives in the Octane
worker while the application around it is rebuilt, so from the second request onwards the fresh
registry never received the three figures. It is an instance flag now, the way its siblings have it.

### Fixed: a figure counts the current brand only

While the family was being wired up this question got four different answers, and side by side on
one screen that is worse than none: one tile showed three other brands' turnover while its
neighbour filtered correctly. The rule now lives once, in `TableMetric::brandScoped()`, transcribed
from `BrandScope::apply()`; this package only names the column, and the figure, the chart and every
breakdown narrow together.

With no brand selected the tile reads **0 and stays**. A reader can make sense of a zero; a tile
that is not there he cannot notice.

## [2.0.1] — 2026-08-25

### Fixed

- **"Add to calendar" answered 404 on every multi-brand installation.** The single-occurrence route
  looked the row up through the brand scope, which fails closed — and a website visitor has no
  brand: the Control Panel reads one from the session, nobody else has one. So the button on every
  public page did nothing while the row sat right there in the table. No error, no log line, just a
  link that led nowhere.

  The UUID is the address, as this route has always promised. It is now resolved outside the scope,
  and what decides whether an occurrence may be handed out is the visibility check, which is
  unchanged: `private` and unpublished stay 404 (not 403 — a 403 confirms the id exists), `unlisted`
  stays reachable by its link, which is what unlisted means. A uuid5 is not guessable.

  Covered by `tests/Feature/CalendarLinkAcrossBrandsTest.php`. Worth noting why it took an outside
  installation to find: `tests/TestCase.php` pins `brand-context.multi_brand` to `false`, and the
  `enableMultiBrand()` helper it ships was never called anywhere in the suite. The case did not
  exist, so it could not fail.

## [2.0.0] — 2026-08-09

### Changed — the licence is now proprietary

This is a paid Marketplace addon. `composer.json` declares `proprietary` and the
licence file carries the commercial addon licence instead of MIT. Entitlement is
enforced by the Statamic Marketplace, not by code in this package.

Tags up to and including `v1.0.1` remain MIT. The change takes effect with the next
release.

## [1.0.1] — 2026-08-05

### Fixed

- The breakpoint-less single-column grid utility is no longer used on the detail screen. Every
  addon in this family ships its own Tailwind build and all of them land in the same
  `addon-utilities` layer; media queries add no specificity, so that bare rule from whichever
  addon stylesheet loads last won against this screen's `lg:` variant and pinned the grid to one
  column at every width. Invisible when this addon is checked alone, visible as soon as two
  addons of the family are installed together. A grid falls back to one column on its own, and
  the overflow guard the utility's `minmax(0,1fr)` track provided is now explicit on the panels.

## [1.0.0] — 2026-08-02

### Added

- Events with a title, slug, description, type, visibility, status and timezone.
- Occurrences: any number of dates per event, each with an optional end, an optional all-day flag,
  an optional timezone override and a venue and/or online URL.
- Timezones: instants stored in UTC, rendered in the event's own zone. Occurrences may override it.
- ICS download per occurrence and a subscribable calendar feed per collection or type.
- Control Panel: an events listing with filters, blueprint-driven publish forms for events and
  dates, an event detail screen with occurrence management, and the `view events` /
  `manage events` permissions.
- Antlers tags `{{ events }}`, `{{ events:occurrences }}`, `{{ events:upcoming }}`,
  `{{ events:next }}`, `{{ events:count }}`, `{{ events:feed_url }}` and `{{ events:ics_url }}`.
- Domain events `EventPublished`, `OccurrenceScheduled`, `OccurrenceCancelled` and
  `OccurrenceRescheduled`.
- Optional `statamic-activity` bridge, attached by `class_exists` and never a Composer requirement.
