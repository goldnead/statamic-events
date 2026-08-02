<?php

namespace Goldnead\Events\Events;

use Carbon\CarbonImmutable;
use Goldnead\Events\Models\Occurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A date moved.
 *
 * Carries where it moved *from*, because that is the part a listener cannot
 * reconstruct afterwards and the part a "the workshop moved to …" notification
 * needs in order to be worth sending.
 */
class OccurrenceRescheduled
{
    use Dispatchable;

    public function __construct(
        public readonly Occurrence $occurrence,
        public readonly CarbonImmutable $previousStartsAt,
        public readonly ?CarbonImmutable $previousEndsAt = null,
    ) {}
}
