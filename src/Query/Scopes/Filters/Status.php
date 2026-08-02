<?php

namespace Goldnead\Events\Query\Scopes\Filters;

use Goldnead\Events\Enums\EventStatus;

class Status extends EventFilter
{
    protected static $handle = 'events_status';

    protected $pinned = true;

    public static function title()
    {
        return __('events::cp.filter_status');
    }

    public function fieldItems()
    {
        return [
            'status' => [
                'display' => __('events::cp.filter_status'),
                'type' => 'select',
                'clearable' => true,
                'placeholder' => __('events::cp.filter_any'),
                'options' => EventStatus::options(),
            ],
        ];
    }

    public function apply($query, $values)
    {
        if (! $value = $values['status'] ?? null) {
            return;
        }

        $query->where('status', $value);
    }

    public function badge($values)
    {
        return __('events::cp.filter_status').': '.($values['status'] ?? '');
    }
}
