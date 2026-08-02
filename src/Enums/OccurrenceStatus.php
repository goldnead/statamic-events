<?php

namespace Goldnead\Events\Enums;

enum OccurrenceStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';

    /** RFC 5545 §3.8.1.11 STATUS for a VEVENT. */
    public function icsStatus(): string
    {
        return match ($this) {
            self::Scheduled => 'CONFIRMED',
            self::Cancelled => 'CANCELLED',
        };
    }

    /** @return array<string, string> handle => label, for a Select field. */
    public static function options(): array
    {
        return [
            self::Scheduled->value => __('events::cp.occurrence_scheduled'),
            self::Cancelled->value => __('events::cp.occurrence_cancelled'),
        ];
    }
}
