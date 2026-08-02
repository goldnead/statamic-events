<?php

namespace Goldnead\Events\Query\Scopes\Filters;

use Goldnead\Events\Enums\Visibility;

/**
 * Named VisibilityFilter rather than Visibility so it does not collide with the
 * enum of that name in a `use` block. Statamic derives the filter handle from
 * `$handle`, not from the class name, so the awkward name costs nothing outside
 * this file.
 */
class VisibilityFilter extends EventFilter
{
    protected static $handle = 'events_visibility';

    public static function title()
    {
        return __('events::cp.filter_visibility');
    }

    public function fieldItems()
    {
        return [
            'visibility' => [
                'display' => __('events::cp.filter_visibility'),
                'type' => 'select',
                'clearable' => true,
                'placeholder' => __('events::cp.filter_any'),
                'options' => Visibility::options(),
            ],
        ];
    }

    public function apply($query, $values)
    {
        if (! $value = $values['visibility'] ?? null) {
            return;
        }

        $query->where('visibility', $value);
    }

    public function badge($values)
    {
        return __('events::cp.filter_visibility').': '.($values['visibility'] ?? '');
    }
}
