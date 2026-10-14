<?php

namespace Goldnead\Events\Support;

use Goldnead\Events\Models\Event;
use Statamic\Auth\User;

/**
 * Who may do what with RSVPs. Three rules, all here.
 *
 * - Answering needs a signed-in account and an event the account may see: a
 *   published one that is not private. A draft or a private event answers like
 *   an unknown one, so an RSVP cannot confirm that it exists.
 * - Seeing who answered is for those who run the calendar (`manage events`).
 * - Everyone else gets the numbers, never the names.
 */
class RsvpPolicy
{
    public static function enabled(): bool
    {
        return (bool) config('events.rsvp.enabled', false);
    }

    public static function canRespond(?User $user, Event $event): bool
    {
        return self::enabled()
            && $user !== null
            && $event->isPubliclyReadable();
    }

    public static function canSeeAttendees(?User $user): bool
    {
        return self::enabled() && $user !== null && $user->can('manage events');
    }
}
