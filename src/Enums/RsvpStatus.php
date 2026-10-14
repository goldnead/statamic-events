<?php

namespace Goldnead\Events\Enums;

/** The three answers an account can give to an event. */
enum RsvpStatus: string
{
    case Going = 'going';
    case Interested = 'interested';
    case NotGoing = 'not_going';
}
