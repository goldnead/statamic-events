<?php

namespace Goldnead\Events\Facades;

use Goldnead\Events\EventManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Illuminate\Support\Collection events(array $filters = [])
 * @method static \Illuminate\Support\Collection occurrences(array $filters = [])
 * @method static \Goldnead\Events\Models\Occurrence|null next(array $filters = [])
 * @method static \Illuminate\Support\Collection feed(array $filters = [])
 * @method static \Illuminate\Database\Eloquent\Builder eventQuery(array $filters = [])
 * @method static \Illuminate\Database\Eloquent\Builder occurrenceQuery(array $filters = [])
 *
 * @see EventManager
 */
class Events extends Facade
{
    /**
     * Resolves the class, not the string key. `events` belongs to Laravel's event
     * dispatcher; an addon that claims it replaces the dispatcher and takes the
     * framework with it.
     */
    protected static function getFacadeAccessor(): string
    {
        return EventManager::class;
    }
}
