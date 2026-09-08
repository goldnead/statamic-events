# Changelog

All notable changes to `goldnead/statamic-events` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this package adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Tag names, Antlers tag parameters,
config keys and facade methods are part of the public API from the first release.

## [2.5.0] — 2026-09-08

### Changed: a new event starts in the timezone of the person filling the form in

The timezone field was prefilled from `events.defaults.timezone` and then from `app.timezone`, which
on most installs means UTC — so an editor in Frankfurt typed 19:00 and got 19:00 UTC unless they
remembered to change a field they had no reason to look at.

Four sources now, nearest first:

1. **The logged-in user's**, if the install gave its user blueprint a `timezone` field. Statamic
   carries no per-user timezone of its own — there is no property for it and no preference in the
   core — so this is an invitation rather than an assumption: an install that adds the field is
   read, one that does not notices nothing.
2. The addon's own `events.defaults.timezone`, because somebody set it for events specifically.
3. `statamic.system.display_timezone`, the zone the site already speaks about times in.
4. `app.timezone`, else UTC.

The user's value is checked rather than believed — a free text field eventually contains "MEZ", and
an invalid zone in a required field is a form that will not submit without saying why. Prefill only:
the field stays editable and **existing events are untouched**.

### Documented: the event types were already configurable

`config('events.types')` has driven the list since the type field was built, with the six shipped
values as its default and a host free to replace them; a type removed from the config keeps being
offered on an event that still carries it, because the stored value is a free string. That was true
and untested, which is the same thing as unpromised — there is now a test for both halves.

## [2.4.0] — 2026-09-08

### Changed: the events listing shows an empty state instead of HTTP 500 when its tables are missing

The addon can be installed without its migrations having run — composer pulls the package in, the
nav item appears, and `events` and `event_occurrences` still do not exist. The listing asks both
tables a question while the page is being built (it counts each event's dates with
`withCount('occurrences')`), so the visitor got HTTP 500 and a stack trace for what is really an
unfinished setup. The listing now checks before its first query and renders a setup screen that
names the missing tables and says to run `php artisan migrate`.

The reason does not vanish with the 500: the guarded page writes to the log why it turned somebody
away. Otherwise the site would look installed and never work.

## [2.3.0] — 2026-09-07

### Changed: the event detail screen is the form

The screen used to be read-only. Type, status, visibility, timezone, slug and description sat in a
grey card as key/value pills, and an "Edit" button led to a second screen carrying the same
blueprint. A collection entry has no such break: what is on the screen is the field, and Save sits
top right. This screen now works the same way — same blueprint, same `PATCH /cp/events/{event}`,
core's own `PublishContainer` and tabs, with the sidebar coming from the blueprint's `sidebar` tab.

`GET /cp/events/{event}/edit` is gone with it, and so is the "Edit" row action on the events
listing: it would have been the same link as "View" under a second name. Anything linking to the
edit URL should link to the event's own screen.

The calendar feed URL keeps a panel of its own below the dates. It is not a field of the event — it
belongs to the installation — and it is the only place in the Control Panel that shows it.

### Changed: dates are edited in a stack, not on a page of their own

"Add date" and "Edit" on a single date opened a separate screen and navigated away
from the event. Both now open a stack over the event. The two routes are unchanged and still answer
an ordinary request; the stack asks them for JSON, which is what `Statamic\CP\PublishForm` hands its
own Vue page. Saving goes through core's save pipeline, so the error toast, the 422 field errors and
the dirty-state guard are core's rather than rebuilt here.

### Added: a settings screen under Control Panel → Addon Settings

Eight keys are editable per brand without a deploy: the timezone and visibility a new event starts
with, the five calendar feed keys, and the listing page size. Everything not changed keeps following
`config/events.php`, so an upgrade still moves the defaults.

Screen, form, validation, store and permission check come from
`goldnead/statamic-brand-context` (now required at `^1.13`); this package contributes the field list
in `Goldnead\Events\Support\Settings` and one new permission, `manage events settings`. The two
existing permissions are untouched.

The new permission is held by nobody until it is given to a role: until then the section is
invisible, including to users who may do everything else with this addon.

**The `^1.13` floor is not cosmetic.** Older releases carry the screen but do not apply its values
reliably. On a single-brand install the settings of the addons that registered last were not
applied at all — the screen showed the stored value after a reload while `config()` kept answering
with the packaged one, and the brand switch that would have caught up never happens where there is
one brand. And up to 1.12 a second save of the same section deleted the override made by the first,
without a message. Anyone who set values between 06.09. and this update should check the screen
afterwards: lost values do not come back on their own.

Three keys are deliberately not on the screen, and the group descriptions say so: `cp.enabled` and
`bridges.activity` are read while the application boots, so a switch here would only take effect on
the next deploy, and `types` is a handle-to-label map that no settings field type can edit honestly.

## [2.2.0] — 2026-09-05

### Fixed: the header actions were glued together

`ButtonGroup` is a segmented control in Statamic — one border around everything, dividers instead
of gaps. Right for the list/grid switch, wrong for a header's actions: "All events" had no button
of its own and the primary button sat flush against the "…" menu. The actions are siblings in the
header slot now, the way `Events/Index.vue` and core's own headers do it.

### Fixed: two tests asserted an order the query never promised

The status breakdown is sorted by count, descending, and says nothing about rows that tie. SQLite
and MySQL break a tie differently, so the suite was green locally and red on CI. The tests now
check what is actually guaranteed — the figures, and that they never rise — which is more than
they checked before. A deterministic tie-break belongs in `TableMetric::splitByColumn` of
`statamic-insights`, which thirteen addons carry byte for byte; that is its own ticket.

Pint also tripped over `tests/Fakes/insights-contracts.php`. All three pinned copies are excluded
now, not just one.

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

Neither action declares a `redirect()`, and that is deliberate. A redirect looks like the obvious way
to get a client-side listing to show the new state, and it silently costs the success message: core's
`ActionController::run()` returns from the redirect branch before it reads what the action returned,
so the front end falls back to toasting its own "Action completed". The screen refreshes through the
listing's `refreshing` event instead, which is what `Events/Index.vue` already does.

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
stacked page.

They stack on purpose now, and the reason is the table rather than the missing classes — side by
side is buildable with classes core does emit. Measured: the table needs 988px, two thirds of a
detail screen gives it 739, and the `…` column then sits 242px outside the visible area, reachable
only by scrolling the table sideways. Stacked it gets 1128.

The location column keeps one line per row, the way core's listings do. Letting an address wrap put
every row at 125px on a 390px screen where core's collections listing sits at 49-65 — and the table
scrolled sideways anyway. Rows are 47px now at both widths.

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
