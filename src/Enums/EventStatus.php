<?php

namespace Goldnead\Events\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    /** @return array<string, string> handle => label, for a Select field. */
    public static function options(): array
    {
        return [
            self::Draft->value => __('events::cp.status_draft'),
            self::Published->value => __('events::cp.status_published'),
        ];
    }
}
