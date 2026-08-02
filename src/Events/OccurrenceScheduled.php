<?php

namespace Goldnead\Events\Events;

use Goldnead\Events\Models\Occurrence;
use Illuminate\Foundation\Events\Dispatchable;

/** A date was added to an event. */
class OccurrenceScheduled
{
    use Dispatchable;

    public function __construct(public readonly Occurrence $occurrence) {}
}
