<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\DowntimeEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DowntimeEvent>
 */
class DowntimeEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('now', '+2 months');

        return [
            'depot_id' => Depot::factory(),
            'machine_asset_id' => null,
            'shift_id' => null,
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+2 hours'),
            'category' => DowntimeEvent::CATEGORY_MAINTENANCE,
            'description' => fake()->randomElement([
                'Planned mould change-over',
                'Scheduled machine service',
                'Head rebuild',
            ]),
            'is_planned' => true,
            'origin' => 'manual',
        ];
    }

    public function changeover(): static
    {
        return $this->state(fn (): array => [
            'category' => DowntimeEvent::CATEGORY_CHANGEOVER,
            'origin' => 'system',
        ]);
    }

    public function unplanned(): static
    {
        return $this->state(fn (): array => [
            'category' => DowntimeEvent::CATEGORY_BREAKDOWN,
            'is_planned' => false,
        ]);
    }
}
