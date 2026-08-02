<?php

use Carbon\CarbonImmutable;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Events\Support\Ics;

function ics(): Ics
{
    return app(Ics::class);
}

it('writes a VCALENDAR whose lines end in CRLF, as RFC 5545 requires', function () {
    $occurrence = Occurrence::factory()->create();

    $body = ics()->occurrence($occurrence->fresh()->load('event'));

    expect($body)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->and($body)->toContain("VERSION:2.0\r\n")
        ->and($body)->toContain("PRODID:-//gldnr.studio//statamic-events//EN\r\n")
        ->and($body)->toEndWith("END:VCALENDAR\r\n")
        // A bare LF anywhere would make the file invalid for strict parsers.
        ->and(preg_match('/(?<!\r)\n/', $body))->toBe(0);
});

it('emits timed dates as UTC instants', function () {
    $event = Event::factory()->create(['timezone' => 'Europe/Berlin', 'title' => 'Chorworkshop']);
    $occurrence = $event->occurrences()->create([
        'starts_at' => CarbonImmutable::parse('2026-07-15 19:00', 'Europe/Berlin'),
        'ends_at' => CarbonImmutable::parse('2026-07-15 21:00', 'Europe/Berlin'),
        'venue_name' => 'Alte Oper',
    ]);

    $body = ics()->occurrence($occurrence->load('event'));

    // The `Z` form needs no VTIMEZONE component, and a VTIMEZONE that disagrees
    // with the client's own tz database silently shifts the appointment.
    expect($body)->toContain('DTSTART:20260715T170000Z')
        ->and($body)->toContain('DTEND:20260715T190000Z')
        ->and($body)->toContain('SUMMARY:Chorworkshop');
});

it('gives an all-day date an exclusive end, the way RFC 5545 defines it', function () {
    $event = Event::factory()->create(['timezone' => 'Europe/Berlin']);
    $occurrence = $event->occurrences()->create([
        'starts_at' => CarbonImmutable::parse('2026-07-15 00:00', 'Europe/Berlin'),
        'all_day' => true,
        'venue_name' => 'Alte Oper',
    ]);

    $body = ics()->occurrence($occurrence->load('event'));

    // A one-day event ends on the *following* day. Getting this wrong is the most
    // common iCalendar bug and shows up as every all-day event being a day short.
    expect($body)->toContain('DTSTART;VALUE=DATE:20260715')
        ->and($body)->toContain('DTEND;VALUE=DATE:20260716')
        ->and($body)->not->toContain('DTSTART:2026');
});

it('publishes a cancelled date rather than dropping it', function () {
    $occurrence = Occurrence::factory()->create();
    $uid = $occurrence->uuid;

    $occurrence->cancel('Storm damage');

    $body = ics()->occurrence($occurrence->fresh()->load('event'));

    expect($body)->toContain('STATUS:CANCELLED')
        // The same UID and a raised SEQUENCE are what make a client that already
        // holds this date accept the cancellation instead of keeping the old copy.
        ->and($body)->toContain('UID:'.$uid.'@example.test')
        ->and($body)->toContain('SEQUENCE:1')
        ->and($body)->toContain('COMMENT:Storm damage');
});

it('escapes the characters RFC 5545 reserves', function () {
    $event = Event::factory()->create([
        'title' => 'Workshop; Teil 1, Teil 2',
        'description' => "Line one\nLine two \\ backslash",
    ]);
    $occurrence = Occurrence::factory()->for($event)->create();

    $body = ics()->occurrence($occurrence->load('event'));

    expect($body)->toContain('SUMMARY:Workshop\\; Teil 1\\, Teil 2')
        ->and($body)->toContain('Line one\\nLine two \\\\ backslash');
});

it('folds long lines at 75 octets without splitting a character', function () {
    $event = Event::factory()->create([
        // Multi-byte on purpose: folding on a byte that happens to sit inside a
        // UTF-8 sequence produces a file some parsers reject outright.
        //
        // Nine repetitions, not twelve. Twelve is 276 characters, which SQLite
        // stores without comment and MySQL rejects outright with SQLSTATE 22001 —
        // `title` is varchar(255), and the blueprint validates max:255, so such a
        // title cannot arrive through the Control Panel either. Caught by the
        // MySQL leg on its first run, which is what that leg is for.
        'title' => str_repeat('Chorprobe mit Ümläüten ', 9),
    ]);
    $occurrence = Occurrence::factory()->for($event)->create();

    $body = ics()->occurrence($occurrence->load('event'));

    foreach (explode("\r\n", $body) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }

    // Unfolding must give the title back byte for byte.
    $unfolded = str_replace("\r\n ", '', $body);

    expect($unfolded)->toContain('SUMMARY:'.str_replace(' ', ' ', $event->title));
    expect(mb_check_encoding($body, 'UTF-8'))->toBeTrue();
});

it('names the download after the event and the local date', function () {
    $event = Event::factory()->create(['slug' => 'chorworkshop-frankfurt', 'timezone' => 'Europe/Berlin']);
    $occurrence = $event->occurrences()->create([
        'starts_at' => CarbonImmutable::parse('2026-07-15 23:30', 'Europe/Berlin'),
        'venue_name' => 'Alte Oper',
    ]);

    // 23:30 in Berlin is 21:30 UTC on the same day — but a date named after its
    // UTC day would be wrong for any evening event east of Greenwich.
    expect(ics()->filename($occurrence->load('event')))->toBe('chorworkshop-frankfurt-2026-07-15.ics');
});

it('reduces a filename to something safe for a header', function () {
    $event = Event::factory()->create(['slug' => 'a/b"c;d']);
    $occurrence = Occurrence::factory()->for($event)->create();

    $filename = ics()->filename($occurrence->load('event'));

    // A Content-Disposition value is a header. A stray quote or newline in one is
    // a header-injection primitive and a slash is a directory.
    expect($filename)->toMatch('/^[A-Za-z0-9\-]+\.ics$/');
});
