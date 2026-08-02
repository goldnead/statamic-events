<?php

namespace Goldnead\Events\Query\Scopes\Filters;

use Goldnead\Events\Support\Blueprints;

/**
 * The axis an operator navigates events by, so it is pinned open rather than
 * hidden behind the "add filter" dropdown.
 */
class Type extends EventFilter
{
    protected static $handle = 'events_type';

    protected $pinned = true;

    public static function title()
    {
        return __('events::cp.filter_type');
    }

    public function fieldItems()
    {
        return [
            'type' => [
                'display' => __('events::cp.filter_type'),
                'type' => 'select',
                'clearable' => true,
                'searchable' => true,
                // Taggable, because the stored value is a free string: a type
                // that has been removed from config is still filterable.
                'taggable' => true,
                'placeholder' => __('events::cp.filter_any'),
                'options' => Blueprints::typeOptions(),
            ],
        ];
    }

    public function apply($query, $values)
    {
        if (! $value = $values['type'] ?? null) {
            return;
        }

        $query->where('type', $value);
    }

    public function badge($values)
    {
        return __('events::cp.filter_type').': '.($values['type'] ?? '');
    }
}
