<?php

return [

    'nav' => 'Events',
    'title' => 'Events',
    'permission_view' => 'View events',
    'permission_manage' => 'Create, edit and delete events and dates',

    'create_event' => 'Create Event',
    'edit' => 'Edit',
    'view' => 'View',
    'delete' => 'Delete',
    'delete_event' => 'Delete event',
    'delete_event_confirm' => 'Deleting the event deletes all of its dates. Anyone who has subscribed to a calendar keeps the dates they already imported.',
    'back_to_events' => 'All events',

    'dates' => 'Dates',
    'no_dates' => 'This event has no dates yet.',
    'no_upcoming' => 'No upcoming date',
    'add_date' => 'Add a date to :event',
    'add_date_short' => 'Add date',
    'all_day' => 'All day',
    // The titles of the two listing actions. Their confirmation and success
    // texts sit further down with the bulk_* keys, because one action serves
    // both a single row and a checked selection.
    'cancel_date' => 'Cancel date',
    'delete_date' => 'Delete date',
    'download_ics' => 'ICS',
    'calendar_feed' => 'Calendar feed',

    'tab_details' => 'Details',
    'tab_settings' => 'Settings',
    'tab_date' => 'Date',

    'field_title' => 'Title',
    'field_slug' => 'Slug',
    'field_slug_instructions' => 'Used in URLs and in the ICS filename. Unique per brand.',
    'field_description' => 'Description',
    'field_type' => 'Type',
    'field_status' => 'Status',
    'field_visibility' => 'Visibility',
    'field_visibility_instructions' => 'Public events appear in calendar feeds. Unlisted ones are reachable only by their link. Private ones never leave the Control Panel.',
    'field_timezone' => 'Timezone',
    'field_timezone_instructions' => 'The zone this event\'s dates are read in. Individual dates can override it.',

    'field_starts_at' => 'Starts',
    'field_ends_at' => 'Ends',
    'field_ends_at_instructions' => 'Optional. Leave empty for an open end.',
    'field_all_day' => 'All-day',
    'field_all_day_instructions' => 'Times are ignored and the date is published as a full day.',
    'field_occurrence_timezone' => 'Timezone override',
    'field_occurrence_timezone_instructions' => 'Only when this date happens somewhere other than the event\'s own timezone.',
    'field_occurrence_status' => 'Status',
    'field_occurrence_status_instructions' => 'Set by cancelling the date, so a cancellation always carries a reason and a bumped sequence.',

    'section_location' => 'Location',
    'section_location_instructions' => 'A venue, an online URL, or both. At least one is required.',
    'field_venue_name' => 'Venue',
    'field_venue_address' => 'Address',
    'field_venue_city' => 'City',
    'field_venue_country' => 'Country',
    'field_online_url' => 'Online URL',
    'field_online_url_instructions' => 'The link attendees join through.',

    'validation_needs_location' => 'A date needs a venue or an online URL.',
    'validation_ends_before_starts' => 'The end cannot be before the start.',

    'filter_any' => 'Any',
    'filter_type' => 'Type',
    'filter_status' => 'Status',
    'filter_visibility' => 'Visibility',

    'col_title' => 'Title',
    'col_type' => 'Type',
    'col_next' => 'Next date',
    'col_dates' => 'Dates',
    'col_status' => 'Status',
    'col_visibility' => 'Visibility',

    // The columns of the dates listing on an event's screen.
    'col_period' => 'When',
    'col_timezone' => 'Timezone',
    'col_location' => 'Location',

    'bulk_cancel_button' => 'Cancel date|Cancel :count dates',
    'bulk_cancel_confirm' => 'The date stays visible and is published as cancelled, so the cancellation reaches everybody who already has it in their calendar.|The :count dates stay visible and are published as cancelled, so the cancellation reaches everybody who already has them in their calendar.',
    'bulk_cancelled' => 'Date cancelled.|:count dates cancelled.',
    'bulk_delete_button' => 'Delete date|Delete :count dates',
    'bulk_delete_confirm' => 'Deleting removes the date entirely. Subscribers keep it in their calendar. If somebody might already have it, cancel it instead.|Deleting removes the :count dates entirely. Subscribers keep them in their calendar. If somebody might already have them, cancel them instead.',
    'bulk_deleted' => 'Date deleted.|:count dates deleted.',

    'status_draft' => 'Draft',
    'status_published' => 'Published',
    'visibility_public' => 'Public',
    'visibility_unlisted' => 'Unlisted',
    'visibility_private' => 'Private',
    'occurrence_scheduled' => 'Scheduled',
    'occurrence_cancelled' => 'Cancelled',

    // The figures this addon offers the Insights dashboard, if that addon is
    // installed. See src/Integrations/Insights/.
    'metric_group' => 'Events',
    'metric_published' => 'Published events',
    'metric_published_description' => 'Events that became visible in this period. Drafts are not counted, because an event nobody can see has not happened yet.',
    'metric_occurrences' => 'Dates',
    'metric_occurrences_description' => 'Dates that fall in this period, cancelled ones included. Counted by when they take place, so the chart reaches into the future when the period does.',
    'metric_cancelled' => 'Cancelled dates',
    'metric_cancelled_description' => 'Dates called off in this period, counted on the day the cancellation went out rather than the day they would have been.',
    'metric_breakdown_type' => 'Type',
    'metric_breakdown_status' => 'Status',
    'metric_no_type' => 'No type',
    'metric_no_status' => 'No status',

    'empty_heading' => 'Events, their dates, and a calendar feed people can subscribe to.',
    'empty_create_description' => 'One event, as many dates as it has. The description is written once.',
    'empty_docs_heading' => 'Read the documentation',
    'empty_docs_description' => 'Antlers tags, the ICS feed and the four domain events other addons can listen to.',
    'empty_dates_description' => 'A start, an end and a place. Everything else is already on the event.',

];
