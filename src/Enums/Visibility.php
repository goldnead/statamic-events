<?php

namespace Goldnead\Events\Enums;

/**
 * Who may see an event, independently of whether it is published.
 *
 * `status` answers "is this finished?", `visibility` answers "who is it for?".
 * Collapsing the two — the tempting shortcut — leaves no way to express a
 * finished event that is deliberately not listed, which is exactly what a
 * private workshop for one choir is.
 */
enum Visibility: string
{
    /** Listed in calendar feeds and reachable by URL. */
    case Public = 'public';

    /** Not in any feed; reachable by anyone holding the occurrence UUID. */
    case Unlisted = 'unlisted';

    /** Control Panel only. Never leaves the CP, not even with the UUID. */
    case Private = 'private';

    /** May a stranger holding the occurrence UUID download the ICS? */
    public function isAddressable(): bool
    {
        return $this !== self::Private;
    }

    /** May it appear in a calendar feed? */
    public function isListable(): bool
    {
        return $this === self::Public;
    }

    /** @return array<string, string> handle => label, for a Select field. */
    public static function options(): array
    {
        return [
            self::Public->value => __('events::cp.visibility_public'),
            self::Unlisted->value => __('events::cp.visibility_unlisted'),
            self::Private->value => __('events::cp.visibility_private'),
        ];
    }
}
