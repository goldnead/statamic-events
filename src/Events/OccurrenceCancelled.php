<?php

namespace Goldnead\Events\Events;

use Goldnead\Events\Models\Occurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A date will not happen.
 *
 * The row survives cancellation: subscribers already hold its UID, and the only
 * way to tell them is to publish the same UID again with STATUS:CANCELLED.
 */
class OccurrenceCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly Occurrence $occurrence,
        public readonly ?string $reason = null,
    ) {}
}
