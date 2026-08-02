<?php

namespace Goldnead\Events\Exceptions;

use RuntimeException;

/**
 * A date nobody can get to.
 *
 * Both location fields are optional individually and the pair is not: an
 * occurrence with neither a venue nor a URL cannot be attended, cannot produce
 * an ICS LOCATION and cannot be described to anyone.
 */
class UnlocatableOccurrence extends RuntimeException
{
    public static function missingBoth(): self
    {
        return new self(
            'An occurrence needs a venue name or an online URL. Both are optional on their own; '
            .'having neither leaves a date nobody can attend.'
        );
    }
}
