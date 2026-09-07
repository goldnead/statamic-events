<?php

/*
 * Labels for the settings form under /cp/brand-settings.
 *
 * Key-for-key identical with resources/lang/de/settings.php. Field keys are the
 * config path with underscores instead of dots (`feeds.past_days` →
 * `feeds_past_days`), because a dot in a translation key reads as a path
 * separator.
 *
 * Descriptions say what happens when the value changes, not what the field is
 * called.
 */

return [

    'groups' => [

        'defaults' => [
            'title' => 'New events',
            'description' => 'What a newly created event starts with. Existing events are untouched. The list of event types stays in config/events.php: it is a handle-to-label map, and changing a handle would hit every event sitting on it.',
        ],

        'feeds' => [
            'title' => 'Calendar feeds',
            'description' => 'The public ICS feed subscribers add to their calendar. Changes take effect on the next request the cache below lets through.',
        ],

        'cp' => [
            'title' => 'Control Panel',
            'description' => 'The switch that turns this addon\'s Control Panel off entirely (cp.enabled) stays in config/events.php: it is read when the routes and the navigation item are registered, which is before settings from here can apply. For the same reason bridges.activity stays there — the bridge to the activity addon attaches during boot.',
        ],

    ],

    'fields' => [

        'defaults_timezone' => [
            'label' => 'Timezone',
            'description' => 'The zone a newly created event\'s dates are read in. Empty means the application timezone. An IANA identifier, e.g. Europe/Berlin.',
        ],

        'defaults_visibility' => [
            'label' => 'Visibility',
            'description' => 'Whether a newly created event appears in the calendar feed. Unlisted ones are reachable only by their link, private ones never leave the Control Panel.',
        ],

        'feeds_enabled' => [
            'label' => 'Serve the feed',
            'description' => 'Off means the feed stops answering. Whoever subscribed keeps the dates already imported but receives no new ones.',
        ],

        'feeds_name' => [
            'label' => 'Calendar name',
            'description' => 'The name a subscriber sees in their calendar application. Empty means the application name.',
        ],

        'feeds_past_days' => [
            'label' => 'Past days',
            'description' => 'How far back the feed reaches. At 0 a date disappears on the day it took place.',
        ],

        'feeds_max_occurrences' => [
            'label' => 'Dates per request',
            'description' => 'The cap on a single feed response. The feed is public and unauthenticated; without a cap one query can be made arbitrarily expensive from the outside.',
        ],

        'feeds_cache_seconds' => [
            'label' => 'Cache',
            'description' => 'How long a feed response is reused, in seconds. For that long a subscriber will not see a change. 0 turns the cache off.',
        ],

        'cp_per_page' => [
            'label' => 'Rows per page',
            'description' => 'How many events the Control Panel listing shows at once, as long as nobody picks something else.',
        ],

    ],

];
