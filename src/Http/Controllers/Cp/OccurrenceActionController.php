<?php

namespace Goldnead\Events\Http\Controllers\Cp;

use Goldnead\Events\Models\Occurrence;
use Statamic\Http\Controllers\CP\ActionController;

/**
 * The two endpoints core's `<Listing>` talks to for row and bulk actions:
 * `POST …/actions/list` asks which of the registered actions apply to a
 * selection, `POST …/actions` runs one. Both are core's; all this class
 * supplies is the lookup from a checked id back to a model.
 *
 * The lookup deliberately goes through the model's default query rather than
 * around it. The brand scope is what keeps an operator in brand A from acting
 * on a brand B row by posting its id, and this is a route that takes ids
 * straight from the browser.
 */
class OccurrenceActionController extends ActionController
{
    protected function getSelectedItems($items, $context)
    {
        $selected = collect($items)->unique();

        $found = Occurrence::query()->with('event')->whereKey($selected->all())->get();

        /*
         * An id that resolves to nothing is either gone or behind the brand
         * scope, and neither is a selection to quietly shrink.
         *
         * `Action::visibleToBulk()` decides by comparing counts, so an empty
         * collection satisfies every action in the Control Panel — core would
         * answer the list request with all of them, including ones that then
         * throw while serialising because their fields expect a context this
         * route does not have. Verified against the playground: posting one
         * foreign-brand id returned core's asset actions and a 500.
         */
        abort_unless($found->count() === $selected->count(), 404);

        return $found;
    }
}
