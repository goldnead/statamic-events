# Security Policy

## Supported versions

The latest minor release receives security fixes.

## Reporting a vulnerability

Please report privately to **info@adriangoldner.com** rather than in a public issue. Include the
version, what an attacker can reach, and the smallest reproduction you have.

## What this package exposes

Two unauthenticated routes, by design — a calendar subscription is a URL a phone fetches with no
session:

- `/!/events/calendar.ics` — contains published, public events only, capped at
  `events.feeds.max_occurrences`.
- `/!/events/occurrences/{uuid}.ics` — the UUID is the capability. Unlisted events are reachable
  with it; drafts and private events answer 404 rather than 403, because a 403 confirms that the id
  exists.

Everything in the Control Panel is behind `view events` or `manage events`, checked in the
controller on every action rather than only in the template.
