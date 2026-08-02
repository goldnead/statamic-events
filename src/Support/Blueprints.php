<?php

namespace Goldnead\Events\Support;

use Goldnead\Events\Enums\EventStatus;
use Goldnead\Events\Enums\OccurrenceStatus;
use Goldnead\Events\Enums\Visibility;
use Goldnead\Events\Models\Event;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Blueprint as BlueprintInstance;

/**
 * The blueprints behind the two Control Panel forms.
 *
 * Built in PHP rather than shipped as YAML because both option lists are
 * dynamic: the type list comes from config, and the occurrence form's date
 * fields are shown in the *event's* timezone, which is not knowable until the
 * event is.
 *
 * Both forms are rendered by `Statamic\CP\PublishForm`, which is `Responsable`
 * and renders core's own `PublishForm` Inertia page. That is the documented
 * zero-Vue path for a blueprint-driven form (ui-vocabulary §2.7 option 1), and
 * it means the two forms in this addon are literally core's form rather than a
 * copy that has to be kept looking like it.
 */
class Blueprints
{
    public static function event(?string $currentType = null): BlueprintInstance
    {
        return Blueprint::make()->setContents([
            'tabs' => [
                'main' => [
                    'display' => __('events::cp.tab_details'),
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'title',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('events::cp.field_title'),
                                        'validate' => ['required', 'max:255'],
                                    ],
                                ],
                                [
                                    'handle' => 'slug',
                                    'field' => [
                                        'type' => 'slug',
                                        'from' => 'title',
                                        'display' => __('events::cp.field_slug'),
                                        'instructions' => __('events::cp.field_slug_instructions'),
                                        'validate' => ['required', 'max:191'],
                                    ],
                                ],
                                [
                                    'handle' => 'description',
                                    'field' => [
                                        'type' => 'textarea',
                                        'display' => __('events::cp.field_description'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'sidebar' => [
                    'display' => __('events::cp.tab_settings'),
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'type',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('events::cp.field_type'),
                                        'options' => self::typeOptions($currentType),
                                        'clearable' => false,
                                        'validate' => ['required'],
                                    ],
                                ],
                                [
                                    'handle' => 'status',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('events::cp.field_status'),
                                        'options' => EventStatus::options(),
                                        'clearable' => false,
                                        'validate' => ['required'],
                                    ],
                                ],
                                [
                                    'handle' => 'visibility',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('events::cp.field_visibility'),
                                        'instructions' => __('events::cp.field_visibility_instructions'),
                                        'options' => Visibility::options(),
                                        'clearable' => false,
                                        'validate' => ['required'],
                                    ],
                                ],
                                [
                                    'handle' => 'timezone',
                                    'field' => [
                                        // Core's own timezone dictionary, so the
                                        // list stays in step with the tz database
                                        // core ships against rather than with a
                                        // copy taken on the day this was written.
                                        'type' => 'dictionary',
                                        'dictionary' => 'timezones',
                                        'display' => __('events::cp.field_timezone'),
                                        'instructions' => __('events::cp.field_timezone_instructions'),
                                        'max_items' => 1,
                                        'validate' => ['required'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * The occurrence form.
     *
     * The two date fields carry `timezone` = the event's zone, so an editor
     * entering "19:00" for a concert in Tokyo enters Tokyo's 19:00 rather than
     * their own. The instant Statamic hands back is still UTC underneath; only
     * what the editor reads changes.
     */
    public static function occurrence(Event $event, ?string $timezoneOverride = null): BlueprintInstance
    {
        $timezone = $timezoneOverride ?: $event->timezone;

        return Blueprint::make()->setContents([
            'tabs' => [
                'main' => [
                    'display' => __('events::cp.tab_date'),
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'starts_at',
                                    'field' => [
                                        'type' => 'date',
                                        'display' => __('events::cp.field_starts_at'),
                                        'time_enabled' => true,
                                        'timezone' => $timezone,
                                        'validate' => ['required'],
                                    ],
                                ],
                                [
                                    'handle' => 'ends_at',
                                    'field' => [
                                        'type' => 'date',
                                        'display' => __('events::cp.field_ends_at'),
                                        'instructions' => __('events::cp.field_ends_at_instructions'),
                                        'time_enabled' => true,
                                        'timezone' => $timezone,
                                    ],
                                ],
                                [
                                    'handle' => 'all_day',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('events::cp.field_all_day'),
                                        'instructions' => __('events::cp.field_all_day_instructions'),
                                        'default' => false,
                                    ],
                                ],
                            ],
                        ],
                        [
                            'display' => __('events::cp.section_location'),
                            'instructions' => __('events::cp.section_location_instructions'),
                            'fields' => [
                                [
                                    'handle' => 'venue_name',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('events::cp.field_venue_name'),
                                        'validate' => ['nullable', 'max:191'],
                                    ],
                                ],
                                [
                                    'handle' => 'venue_address',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('events::cp.field_venue_address'),
                                        'validate' => ['nullable', 'max:255'],
                                    ],
                                ],
                                [
                                    'handle' => 'venue_city',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('events::cp.field_venue_city'),
                                        'width' => 50,
                                        'validate' => ['nullable', 'max:191'],
                                    ],
                                ],
                                [
                                    'handle' => 'venue_country',
                                    'field' => [
                                        'type' => 'dictionary',
                                        'dictionary' => 'countries',
                                        'display' => __('events::cp.field_venue_country'),
                                        'max_items' => 1,
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'online_url',
                                    'field' => [
                                        'type' => 'text',
                                        'input_type' => 'url',
                                        'display' => __('events::cp.field_online_url'),
                                        'instructions' => __('events::cp.field_online_url_instructions'),
                                        'validate' => ['nullable', 'url', 'max:512'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'sidebar' => [
                    'display' => __('events::cp.tab_settings'),
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'timezone',
                                    'field' => [
                                        'type' => 'dictionary',
                                        'dictionary' => 'timezones',
                                        'display' => __('events::cp.field_occurrence_timezone'),
                                        'instructions' => __('events::cp.field_occurrence_timezone_instructions'),
                                        'max_items' => 1,
                                    ],
                                ],
                                [
                                    'handle' => 'status',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('events::cp.field_occurrence_status'),
                                        'options' => OccurrenceStatus::options(),
                                        'clearable' => false,
                                        'read_only' => true,
                                        'instructions' => __('events::cp.field_occurrence_status_instructions'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * The type options offered by the Control Panel.
     *
     * A stored type that is no longer configured is added back to the list, so
     * opening an old event does not silently rewrite its type to whatever
     * happens to sort first.
     *
     * @return array<string, string>
     */
    public static function typeOptions(?string $current = null): array
    {
        $options = collect((array) config('events.types', []))
            ->mapWithKeys(fn ($label, $handle) => [(string) $handle => (string) $label])
            ->all();

        if (filled($current) && ! array_key_exists($current, $options)) {
            $options[$current] = $current;
        }

        return $options;
    }
}
