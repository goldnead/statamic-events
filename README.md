# Statamic Events

Events with as many dates as they actually have, in the timezone they actually happen in, with an
ICS download per date and a calendar feed people can subscribe to.

A Statamic 6 addon. Brand-scoped through
[`goldnead/statamic-brand-context`](https://github.com/goldnead/statamic-brand-context), which is
its only hard dependency — a concert calendar on an artist's website installs without a CRM.

---

## Why not a collection with a date field

Because an event and its dates are not the same thing. One entry per date duplicates the
description as many times as there are dates, and leaves nowhere to record that the third date is
cancelled while the others still stand. So the description lives on the event and the dates live on
its occurrences, and a cancellation is a property of one date rather than the removal of an entry.

---

## Requirements

| | |
|---|---|
| PHP | 8.2+ |
| Laravel | 12.40+ or 13 |
| Statamic | 6.0+ |
| Database | MySQL, MariaDB, PostgreSQL or SQLite |

## Installation

```bash
composer require goldnead/statamic-events
php artisan migrate
```

The Control Panel bundle is committed, so there is nothing to build. Publish it if your deployment
serves `public/vendor` from disk:

```bash
php artisan vendor:publish --tag=events
```

Optional:

```bash
php artisan vendor:publish --tag=events-config        # config/events.php
php artisan vendor:publish --tag=events-translations  # lang/vendor/events
php artisan vendor:publish --tag=events-migrations
```

## Permissions

| Permission | Grants |
|---|---|
| `view events` | The Events screens, read-only |
| `manage events` | Creating, editing, cancelling and deleting events and their dates |

Both are registered in the `events` group. `manage events` is a child of `view events`.

---

## Usage

Create an event in the Control Panel under **Content → Events**, give it as many dates as it has,
and put one of the tags below on a page. Nothing else is required — no collection, no blueprint to
author, no route to register.

```antlers
{{ events:upcoming limit="5" }}
    <article>
        <h3>{{ event:title }}</h3>
        <time datetime="{{ starts_at_utc format="c" }}">
            {{ starts_at format="D, d.m.Y H:i" }} ({{ timezone }})
        </time>
        <p>{{ location }}</p>
        <a href="{{ ics_url }}">Add to calendar</a>
    </article>
{{ /events:upcoming }}

<a href="{{ events:feed_url }}">Subscribe to the whole calendar</a>
```

From PHP, the same data comes through the `Events` facade; from another addon, through the four
domain events. Both are documented below.

---

## The model

**Event** — title, slug, description, type, visibility, status, timezone.

**Occurrence** — one date of an event: start, optional end, optional all-day flag, an optional
timezone override, a venue and/or an online URL, and a status.

Two rules the addon enforces rather than suggests:

- **Every date needs a location.** A venue name or an online URL, or both. Both may be blank
  individually; having neither is rejected, because a date nobody can attend is not a date.
- **Times are UTC on disk and rendered in the event's timezone.** Not the viewer's. A concert in
  Tokyo is at 19:00 in Tokyo whoever is reading the page. An occurrence can override the event's
  zone, which is what a tour needs.

### Status and visibility are separate

`status` answers "is this finished?" — `draft` or `published`.
`visibility` answers "who is it for?":

| Visibility | In the calendar feed | Reachable by link | In the Control Panel |
|---|---|---|---|
| `public` | yes | yes | yes |
| `unlisted` | no | yes | yes |
| `private` | no | no | yes |

A draft is never publicly readable whatever its visibility.

### Cancelling, not deleting

`$occurrence->cancel('Storm damage')` keeps the row, sets `STATUS:CANCELLED` and raises the RFC 5545
`SEQUENCE`. That is the only way a cancellation reaches somebody who already imported the date —
deleting it leaves the concert in their calendar forever. Cancelling twice is a no-op.

---

## Antlers tags

### `{{ events }}`

Lists events. Published and non-private only.

| Parameter | Default | |
|---|---|---|
| `type` | — | One or more types, comma separated |
| `limit` | — | |
| `listable` | `true` | `false` also includes unlisted events. Never private ones. |

```antlers
{{ events type="concert" limit="5" }}
    <h2>{{ title }}</h2>
    {{ description }}
{{ /events }}
```

### `{{ events:occurrences }}`

Lists dates, oldest first.

| Parameter | Default | |
|---|---|---|
| `event` | — | An event slug |
| `type` | — | |
| `from` / `to` | — | Any parseable date |
| `limit` | — | |
| `order` | `asc` | `asc` or `desc` |
| `include_cancelled` | `true` | |
| `listable` | `true` | |

Each date exposes `starts_at` and `ends_at` **in the date's own timezone**, `starts_at_utc` and
`ends_at_utc` for arithmetic, `timezone`, `all_day`, `cancelled`, `cancellation_reason`, `online`,
`online_url`, the `venue_*` fields, a one-line `location`, an `ics_url`, and the whole `event`.

```antlers
{{ events:occurrences event="chorworkshop-frankfurt" }}
    <li{{ if cancelled }} class="cancelled"{{ /if }}>
        {{ starts_at format="D, d.m.Y H:i" }} ({{ timezone }}) — {{ location }}
        <a href="{{ ics_url }}">Add to calendar</a>
    </li>
{{ /events:occurrences }}
```

### `{{ events:upcoming }}`

The same, from now on, with cancelled dates dropped. `from` moves the horizon rather than being
ignored.

### `{{ events:next }}`

The next date somebody can attend, or nothing to loop over. Cancelled dates are never "next".

### `{{ events:count }}`

The number of matching dates. Takes the same parameters as `{{ events:occurrences }}`.

### `{{ events:feed_url }}` and `{{ events:ics_url }}`

```antlers
<a href="{{ events:feed_url type="concert" }}">Subscribe to the concert calendar</a>
<a href="{{ events:ics_url occurrence="{{ id }}" }}">Add this date</a>
```

---

## Calendar output

| URL | |
|---|---|
| `/!/events/calendar.ics` | The feed. `?type=` narrows it. |
| `/!/events/occurrences/{uuid}.ics` | One date. |

Both are unauthenticated — a calendar subscription is a URL a phone fetches with no session — and
both are built from the visibility rules above. `type` can only ever narrow; nothing in the query
string can widen what a feed contains.

Three things worth knowing about the output:

- **Times are emitted as UTC instants** (`DTSTART:20260715T170000Z`). The alternative,
  `DTSTART;TZID=…`, means shipping a VTIMEZONE component with correct historical DST transitions in
  every response, and one that disagrees with the client's own tz database silently shifts the
  appointment. Every client renders a UTC instant in the reader's local time, which is what a
  calendar is for. The addon's own rendering still uses the event's zone.
- **All-day dates use `VALUE=DATE` with an exclusive end**, per RFC 5545 §3.8.2.2 — a one-day event
  ends on the following day.
- **Cancelled dates stay in the feed**, with `STATUS:CANCELLED` and a raised `SEQUENCE`.

Feeds are capped at `events.feeds.max_occurrences` (default 500) and reach `events.feeds.past_days`
into the past (default 1), so a date that finished this morning does not vanish mid-day.

---

## Domain events

| Event | Fired when |
|---|---|
| `Goldnead\Events\Events\EventPublished` | An event moves from draft to published. Once per transition. |
| `Goldnead\Events\Events\OccurrenceScheduled` | A date is added |
| `Goldnead\Events\Events\OccurrenceRescheduled` | A date moves. Carries `previousStartsAt` and `previousEndsAt`. |
| `Goldnead\Events\Events\OccurrenceCancelled` | A date is called off. Carries the reason. |

This addon fires; it does not orchestrate. Workflow building belongs in
`statamic-automations`, which can trigger on all four.

## Optional siblings

Bridged by `class_exists`, never as a Composer requirement:

- **`goldnead/statamic-activity`** — records the four domain events into the ledger, with a dedupe
  key per fact. Switch off with `events.bridges.activity`. A failing ledger never fails the write
  that produced the fact: cancelling a concert has to succeed even when the addon next door is
  misconfigured.
- **`goldnead/statamic-automations`** — can trigger on the four domain events with no work here.
- **`goldnead/statamic-notifications`** — groundwork for reminders, planned for v1.2.

## The facade

```php
use Goldnead\Events\Facades\Events;

Events::events(['type' => 'concert']);
Events::occurrences(['event' => 'chorworkshop', 'upcoming' => true]);
Events::next(['type' => 'concert']);
Events::feed();
```

Everything the tags and the calendar routes do goes through these, so the visibility rules exist in
one place.

---

## Not in v1

RSVP, capacity, waiting lists, reminders, access gating, attendance tracking, recurrence-rule
generation, course and community linkage.

Deliberately: RSVP and waiting lists pull identity, notifications and entitlements into the first
release, and reminders pull the scheduler. The core has to carry on its own first. v1.1 takes RSVP,
capacity and waiting lists; v1.2 takes reminders through `statamic-notifications`.

## Development

```bash
composer install && npm install
composer test          # SQLite
composer test:mysql    # the identical suite against a real MySQL server
composer lint          # Pint
composer analyse       # PHPStan level 5
npm test               # the two Control Panel pages
npm run build          # rebuild resources/dist
```

The MySQL leg is not optional in CI. SQLite has no InnoDB key-length limit, no utf8mb4 byte
arithmetic, no fixed column widths and no enforced foreign keys unless asked — and this addon's
cascade from `events` to `event_occurrences` is one.

## License

MIT. See [LICENSE.md](LICENSE.md).
