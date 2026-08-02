<?php

namespace Goldnead\Events\Database\Factories;

use Carbon\CarbonImmutable;
use Goldnead\Events\Enums\OccurrenceStatus;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Occurrence>
 */
class OccurrenceFactory extends Factory
{
    protected $model = Occurrence::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'starts_at' => CarbonImmutable::now('UTC')->addWeek()->setTime(17, 0),
            // Open-ended by default. A fixed default end is a trap: every test
            // that overrides only `starts_at` would silently build a window that
            // ends before it begins, and the model would reject it for reasons
            // that have nothing to do with the test.
            'ends_at' => null,
            'all_day' => false,
            'status' => OccurrenceStatus::Scheduled->value,
            'venue_name' => 'Kulturzentrum',
            'venue_city' => 'Frankfurt',
            'venue_country' => 'DE',
        ];
    }

    /** A fixed two-hour window, for the tests that need an end. */
    public function lasting(int $hours = 2): self
    {
        return $this->state(fn (array $attributes) => [
            'ends_at' => CarbonImmutable::parse($attributes['starts_at'])->addHours($hours),
        ]);
    }

    public function online(): self
    {
        return $this->state(fn () => [
            'venue_name' => null,
            'venue_city' => null,
            'venue_country' => null,
            'online_url' => 'https://example.test/join',
        ]);
    }

    public function allDay(): self
    {
        return $this->state(fn () => [
            'all_day' => true,
            'ends_at' => null,
        ]);
    }

    public function past(): self
    {
        return $this->state(fn () => [
            'starts_at' => CarbonImmutable::now('UTC')->subMonth(),
            'ends_at' => CarbonImmutable::now('UTC')->subMonth()->addHours(2),
        ]);
    }
}
