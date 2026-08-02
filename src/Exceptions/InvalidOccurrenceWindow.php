<?php

namespace Goldnead\Events\Exceptions;

use RuntimeException;

class InvalidOccurrenceWindow extends RuntimeException
{
    public static function endsBeforeItStarts(): self
    {
        return new self('An occurrence cannot end before it starts.');
    }
}
