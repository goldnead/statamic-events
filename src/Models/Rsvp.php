<?php

namespace Goldnead\Events\Models;

use Goldnead\Events\Enums\RsvpStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $event_id
 * @property string $user_id
 * @property RsvpStatus $status
 */
class Rsvp extends Model
{
    protected $table = 'event_rsvps';

    protected $guarded = [];

    protected $casts = ['status' => RsvpStatus::class];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
