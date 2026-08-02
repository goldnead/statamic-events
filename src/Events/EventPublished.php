<?php

namespace Goldnead\Events\Events;

use Goldnead\Events\Models\Event;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An event moved from draft to published.
 *
 * Fired once per transition, not on every save of an already-published event —
 * a listener that sends an announcement must be able to trust that.
 */
class EventPublished
{
    use Dispatchable;

    public function __construct(public readonly Event $event) {}
}
