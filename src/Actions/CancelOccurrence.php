<?php

namespace Goldnead\Events\Actions;

use Goldnead\Events\Models\Occurrence;
use Statamic\Actions\Action;

/**
 * Cancelling a date from the listing on an event's screen.
 *
 * It is a native Statamic action rather than a hand-rolled button so the one
 * mechanism serves both places at once: the "…" menu of a single row and the
 * bulk bar that appears once rows are checked. The confirmation, the field
 * validation, the toast and the authorization check all come from core.
 *
 * `visibleTo()` is the guard that keeps this out of every other listing in the
 * Control Panel: actions are registered in one global registry and core asks
 * every one of them about every item, so an action that answers `true` to
 * anything shows up on the Entries screen.
 */
class CancelOccurrence extends Action
{
    /**
     * Explicit, because the registry is keyed by handle across all addons and
     * the derived one (`cancel_occurrence`) is a name a sibling could pick too.
     */
    protected static $handle = 'events_cancel_occurrence';

    protected $dangerous = true;

    public static function title()
    {
        return __('events::cp.cancel_date');
    }

    public function icon(): string
    {
        return 'x-square';
    }

    public function visibleTo($item)
    {
        return $item instanceof Occurrence && ! $item->isCancelled();
    }

    public function authorize($user, $item)
    {
        return (bool) $user?->can('manage events');
    }

    public function buttonText()
    {
        return trans_choice('events::cp.bulk_cancel_button', $this->items->count());
    }

    public function confirmationText()
    {
        return trans_choice('events::cp.bulk_cancel_confirm', $this->items->count());
    }

    /**
     * Returns the message, and deliberately no `redirect()`.
     *
     * A redirect would look like the obvious way to get the client-side listing
     * to show the new status, because it has no URL of its own to re-fetch from.
     * It is a trap: core's `ActionController::run()` returns from the redirect
     * branch *before* it reaches the message, so the string below would never
     * leave the server and the front end would toast its own "Action completed"
     * instead. The screen refreshes through the listing's `refreshing` event
     * instead — see `Events/Show.vue`.
     */
    public function run($items, $values)
    {
        // The model no-ops on an already cancelled date, so a selection that
        // happens to include one does not emit a second cancellation.
        $items->each->cancel();

        return trans_choice('events::cp.bulk_cancelled', $items->count());
    }
}
