<?php

namespace Goldnead\Events\Http\Controllers;

use Goldnead\Events\EventManager;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Events\Support\Ics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * The public calendar surface: one ICS per date and one feed per filter.
 *
 * Everything here is unauthenticated by design — a calendar subscription is a
 * URL a phone fetches with no session — so every response is built from the
 * visibility rules in `Events`, never from a request parameter that could widen
 * them.
 */
class CalendarController extends Controller
{
    public function __construct(private readonly Ics $ics) {}

    /**
     * One occurrence, addressed by UUID.
     *
     * The UUID is the capability: `unlisted` events are downloadable by anyone
     * holding the link and appear in no feed, which is exactly what "unlisted"
     * is for. `private` and unpublished events are 404 — not 403, because a 403
     * confirms that the id exists.
     */
    public function occurrence(string $uuid): Response
    {
        $occurrence = Occurrence::query()->with('event')->where('uuid', $uuid)->first();

        abort_unless($occurrence && $occurrence->event?->isPubliclyReadable(), 404);

        return $this->respond(
            $this->ics->occurrence($occurrence, $occurrence->event->title),
            $this->ics->filename($occurrence),
        );
    }

    /**
     * A subscribable feed.
     *
     * `type` is the only filter a caller may set. It is a narrowing parameter
     * over an already-filtered set, so the worst a crafted value can do is
     * return nothing.
     */
    public function feed(Request $request): Response
    {
        abort_unless(config('events.feeds.enabled', true), 404);

        $type = $request->string('type')->toString();

        $occurrences = app(EventManager::class)->feed($type === '' ? [] : ['type' => $type]);

        $body = $this->ics->calendar($occurrences, $this->calendarName());

        return $this->respond($body, 'calendar.ics')
            ->header('Cache-Control', 'public, max-age='.max(0, (int) config('events.feeds.cache_seconds', 300)));
    }

    private function calendarName(): string
    {
        return (string) (config('events.feeds.name') ?: config('app.name') ?: 'Calendar');
    }

    /**
     * `text/calendar` with an explicit charset, and a filename that has already
     * been reduced to ASCII by Ics::filename() — a Content-Disposition value is
     * a header, and a stray quote or newline in one is a header-injection
     * primitive.
     */
    private function respond(string $body, string $filename): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
