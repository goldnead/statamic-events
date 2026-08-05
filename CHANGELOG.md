# Changelog

All notable changes to `goldnead/statamic-events` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this package adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Tag names, Antlers tag parameters,
config keys and facade methods are part of the public API from the first release.

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
