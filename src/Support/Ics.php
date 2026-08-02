<?php

namespace Goldnead\Events\Support;

use Carbon\CarbonImmutable;
use Goldnead\Events\Models\Occurrence;

/**
 * RFC 5545 iCalendar output.
 *
 * Deliberately hand-written and deliberately small. The format this addon needs
 * is one VCALENDAR of plain VEVENTs with no attendees, alarms, attachments or
 * recurrence rules, and every library that does more brings a dependency a
 * concert calendar on an artist's website should not have to install.
 *
 * Three decisions worth knowing about:
 *
 * **Times are emitted in UTC** (`DTSTART:20260802T170000Z`, RFC 5545 §3.3.5
 * form 2). The alternative — `DTSTART;TZID=Europe/Berlin:…` — requires shipping
 * a matching VTIMEZONE component with the correct historical DST transitions in
 * every response, and a VTIMEZONE that disagrees with the client's own tz
 * database silently shifts the appointment. The UTC instant is exact and every
 * client renders it in the user's local time, which is what a calendar is for.
 * The addon's *own* rendering still uses the event's zone — that is §"Zeitzonen"
 * of the spec, and it is about the website, not the .ics file.
 *
 * **All-day dates use `VALUE=DATE`** with the local calendar date, because an
 * all-day event has no time of day to convert.
 *
 * **Cancelled occurrences are still emitted**, with STATUS:CANCELLED and a
 * bumped SEQUENCE. Dropping them from the feed is what makes a cancelled
 * concert stay in every subscriber's calendar forever.
 */
class Ics
{
    private const PRODID = '-//gldnr.studio//statamic-events//EN';

    /** RFC 5545 §3.1: lines are folded at 75 octets, continuations start with a space. */
    private const FOLD_AT = 75;

    /**
     * One VCALENDAR containing every occurrence given, in the order given.
     *
     * @param  iterable<Occurrence>  $occurrences
     */
    public function calendar(iterable $occurrences, ?string $name = null): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        if (filled($name)) {
            // X-WR-CALNAME is not in RFC 5545, but it is what Google Calendar and
            // Apple Calendar read to name a subscribed calendar. Without it a
            // subscription shows up as its URL.
            $lines[] = 'X-WR-CALNAME:'.$this->escape($name);
        }

        foreach ($occurrences as $occurrence) {
            $lines = array_merge($lines, $this->componentLines($occurrence));
        }

        $lines[] = 'END:VCALENDAR';

        return $this->render($lines);
    }

    /** A VCALENDAR wrapping a single occurrence — the per-date download. */
    public function occurrence(Occurrence $occurrence, ?string $name = null): string
    {
        return $this->calendar([$occurrence], $name);
    }

    /**
     * A filename a browser can save without renaming it.
     *
     * ASCII only and no path separators: the value lands in a Content-Disposition
     * header, where a stray quote or newline is a header-injection primitive and
     * a slash is a directory.
     */
    public function filename(Occurrence $occurrence): string
    {
        $stem = trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($occurrence->event->slug ?: 'event')), '-');

        return ($stem ?: 'event').'-'.$occurrence->localStart()->format('Y-m-d').'.ics';
    }

    /** @return list<string> */
    private function componentLines(Occurrence $occurrence): array
    {
        $event = $occurrence->event;
        $now = CarbonImmutable::now('UTC');

        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$this->uid($occurrence),
            'DTSTAMP:'.$now->format('Ymd\THis\Z'),
            'SEQUENCE:'.(int) $occurrence->sequence,
            'STATUS:'.$occurrence->status->icsStatus(),
            'SUMMARY:'.$this->escape((string) $event->title),
        ];

        $lines = array_merge($lines, $this->timeLines($occurrence));

        if (filled($event->description)) {
            $lines[] = 'DESCRIPTION:'.$this->escape($event->description);
        }

        if ($location = $occurrence->locationLine()) {
            $lines[] = 'LOCATION:'.$this->escape($location);
        }

        // URL points at the joining link when there is one. A calendar entry for
        // an online date whose only useful content is a URL should carry it in
        // the field clients turn into a button.
        if ($occurrence->isOnline()) {
            $lines[] = 'URL:'.$this->escape($occurrence->online_url);
        }

        if ($occurrence->isCancelled() && filled($occurrence->cancellation_reason)) {
            $lines[] = 'COMMENT:'.$this->escape($occurrence->cancellation_reason);
        }

        if ($occurrence->updated_at) {
            $lines[] = 'LAST-MODIFIED:'.CarbonImmutable::parse($occurrence->updated_at)->utc()->format('Ymd\THis\Z');
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * DTSTART/DTEND.
     *
     * For an all-day date, DTEND is exclusive per RFC 5545 §3.8.2.2 — a one-day
     * event ends on the *following* day. Getting this wrong is the single most
     * common iCalendar bug and shows up as every all-day event spanning one day
     * too few.
     *
     * @return list<string>
     */
    private function timeLines(Occurrence $occurrence): array
    {
        if ($occurrence->all_day) {
            $start = $occurrence->localStart()->startOfDay();
            $end = ($occurrence->localEnd() ?: $start)->startOfDay()->addDay();

            return [
                'DTSTART;VALUE=DATE:'.$start->format('Ymd'),
                'DTEND;VALUE=DATE:'.$end->format('Ymd'),
            ];
        }

        $lines = ['DTSTART:'.$occurrence->starts_at->utc()->format('Ymd\THis\Z')];

        if ($occurrence->ends_at) {
            $lines[] = 'DTEND:'.$occurrence->ends_at->utc()->format('Ymd\THis\Z');
        }

        return $lines;
    }

    /**
     * A UID that is globally unique and stable for the lifetime of the date.
     *
     * Stable is the important half: a cancellation only reaches a subscriber if
     * it arrives under the UID they already hold, so this may never be derived
     * from anything that changes — not the start time, not the title.
     */
    private function uid(Occurrence $occurrence): string
    {
        return $occurrence->uuid.'@'.$this->uidDomain();
    }

    private function uidDomain(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'statamic-events.local';
    }

    /** RFC 5545 §3.3.11: backslash, semicolon, comma and newlines are escaped. */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value
        );
    }

    /**
     * Folds every line to 75 octets and joins with CRLF.
     *
     * Octets, not characters: folding a UTF-8 string on a character boundary
     * that happens to land mid-sequence produces a file some parsers reject, so
     * the split walks back to a code-point boundary.
     *
     * @param  list<string>  $lines
     */
    private function render(array $lines): string
    {
        return collect($lines)
            ->map(fn (string $line) => $this->fold($line))
            ->implode("\r\n")."\r\n";
    }

    private function fold(string $line): string
    {
        if (strlen($line) <= self::FOLD_AT) {
            return $line;
        }

        $chunks = [];
        $remaining = $line;
        $limit = self::FOLD_AT;

        while (strlen($remaining) > $limit) {
            $take = $limit;

            // Walk back off a UTF-8 continuation byte (10xxxxxx) so a multi-byte
            // character is never split across a fold.
            while ($take > 1 && (ord($remaining[$take]) & 0xC0) === 0x80) {
                $take--;
            }

            $chunks[] = substr($remaining, 0, $take);
            $remaining = substr($remaining, $take);

            // Continuation lines carry a leading space, which costs one of the
            // 75 octets.
            $limit = self::FOLD_AT - 1;
        }

        $chunks[] = $remaining;

        return implode("\r\n ", $chunks);
    }

    public static function make(): self
    {
        return new self;
    }
}
