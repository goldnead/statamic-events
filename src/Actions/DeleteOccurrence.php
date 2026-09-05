<?php

namespace Goldnead\Events\Actions;

use Goldnead\Events\Models\Occurrence;
use Statamic\Actions\Action;

/**
 * Deleting a date from the listing on an event's screen.
 *
 * The counterpart to {@see CancelOccurrence}: cancelling keeps the date visible
 * and tells subscribed calendars it is off, deleting removes it. Both are
 * offered from the same "…" menu because the difference matters and hiding one
 * of them behind the other is how a date gets deleted when it should have been
 * cancelled.
 */
class DeleteOccurrence extends Action
{
    protected static $handle = 'events_delete_occurrence';

    protected $dangerous = true;

    public static function title()
    {
        return __('events::cp.delete_date');
    }

    public function icon(): string
    {
        return 'trash';
    }

    public function visibleTo($item)
    {
        return $item instanceof Occurrence;
    }

    public function authorize($user, $item)
    {
        return (bool) $user?->can('manage events');
    }

    public function buttonText()
    {
        return trans_choice('events::cp.bulk_delete_button', $this->items->count());
    }

    public function confirmationText()
    {
        return trans_choice('events::cp.bulk_delete_confirm', $this->items->count());
    }

    /** @see CancelOccurrence::run() for why there is no `redirect()` beside this. */
    public function run($items, $values)
    {
        $items->each->delete();

        return trans_choice('events::cp.bulk_deleted', $items->count());
    }
}
