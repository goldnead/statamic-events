<?php

namespace Goldnead\Events\Events;

use Carbon\CarbonImmutable;
use Goldnead\Events\Models\Occurrence;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The presale start of a date moved — set for the first time, moved, or cleared.
 *
 * Carries where it moved *from*, because "the presale begins on …" is worth
 * sending when it begins and worth resending when it moves, and a listener
 * cannot reconstruct the old instant afterwards. Null means there was none,
 * which is itself an answer: the presale was called off.
 *
 * Not dispatched on creation. A new date announces itself through
 * OccurrenceScheduled, which carries the occurrence — and its presale date —
 * already.
 */
class OccurrencePresaleChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Occurrence $occurrence,
        public readonly ?CarbonImmutable $previousPresaleStartsAt = null,
    ) {}
}
