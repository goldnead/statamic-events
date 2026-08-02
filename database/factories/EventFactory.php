<?php

namespace Goldnead\Events\Database\Factories;

use Goldnead\Events\Enums\EventStatus;
use Goldnead\Events\Enums\Visibility;
use Goldnead\Events\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $title = 'Workshop '.Str::random(6);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => 'A description.',
            'type' => 'workshop',
            'status' => EventStatus::Draft->value,
            'visibility' => Visibility::Public->value,
            // Deliberately not UTC and not the app timezone: a factory that
            // defaults to either hides every conversion bug it is meant to expose.
            'timezone' => 'Europe/Berlin',
        ];
    }

    public function published(): self
    {
        return $this->state(fn () => [
            'status' => EventStatus::Published->value,
            'published_at' => now(),
        ]);
    }

    public function unlisted(): self
    {
        return $this->state(fn () => ['visibility' => Visibility::Unlisted->value]);
    }

    public function private(): self
    {
        return $this->state(fn () => ['visibility' => Visibility::Private->value]);
    }
}
