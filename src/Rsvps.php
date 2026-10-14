<?php

namespace Goldnead\Events;

use Goldnead\Events\Enums\RsvpStatus;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Rsvp;
use Goldnead\Events\Support\RsvpPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Statamic\Auth\User;

/**
 * RSVPs: one answer per account and event. Off unless `events.rsvp.enabled`.
 *
 * Every write goes through the policy here, so a controller, a tag or another
 * addon cannot skip it. The counts are computed, not stored: a counter column
 * is a second copy that drifts the first time a row is deleted by hand.
 */
class Rsvps
{
    /** Going, and again to take it back: the legacy "Ich komme" button. */
    public function toggle(Event $event, User $user): ?Rsvp
    {
        $this->authorize($event, $user);

        $existing = $this->find($event, $user);

        if ($existing?->status === RsvpStatus::Going) {
            $existing->delete();

            return null;
        }

        return $this->set($event, $user, RsvpStatus::Going);
    }

    public function set(Event $event, User $user, RsvpStatus|string $status): Rsvp
    {
        $this->authorize($event, $user);

        return Rsvp::updateOrCreate(
            ['event_id' => $event->getKey(), 'user_id' => (string) $user->getAuthIdentifier()],
            ['brand_id' => $event->brand_id, 'status' => RsvpStatus::from($status instanceof RsvpStatus ? $status->value : $status)],
        );
    }

    public function remove(Event $event, User $user): void
    {
        $this->authorize($event, $user);

        Rsvp::where('event_id', $event->getKey())->where('user_id', (string) $user->getAuthIdentifier())->delete();
    }

    /**
     * The people behind the numbers. Refused to anyone who does not run the
     * calendar.
     *
     * @return Collection<int, Rsvp>
     */
    public function list(Event $event, ?User $viewer): Collection
    {
        if (! RsvpPolicy::canSeeAttendees($viewer)) {
            throw new AuthorizationException;
        }

        return Rsvp::where('event_id', $event->getKey())->orderBy('id')->get();
    }

    /**
     * What anyone may see.
     *
     * @return array{going: int, interested: int, not_going: int}
     */
    public function counts(Event $event): array
    {
        $counts = Rsvp::where('event_id', $event->getKey())
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'going' => (int) ($counts['going'] ?? 0),
            'interested' => (int) ($counts['interested'] ?? 0),
            'not_going' => (int) ($counts['not_going'] ?? 0),
        ];
    }

    public function statusFor(Event $event, ?User $user): ?RsvpStatus
    {
        return $user ? $this->find($event, $user)?->status : null;
    }

    /** For an account deletion: forget every answer the account gave. */
    public function eraseFor(User|string $user): int
    {
        return Rsvp::where('user_id', (string) ($user instanceof User ? $user->getAuthIdentifier() : $user))->delete();
    }

    private function find(Event $event, User $user): ?Rsvp
    {
        return Rsvp::where('event_id', $event->getKey())->where('user_id', (string) $user->getAuthIdentifier())->first();
    }

    private function authorize(Event $event, User $user): void
    {
        if (! RsvpPolicy::canRespond($user, $event)) {
            throw new AuthorizationException;
        }
    }
}
