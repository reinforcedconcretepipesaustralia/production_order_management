<?php

namespace Database\Factories;

use App\Models\DepotShiftPattern;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepotShiftPattern>
 */
class DepotShiftPatternFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // depot_id is derived from the shift when saved, so both default to the same plant.
        $shift = Shift::factory();

        return [
            'depot_id' => fn (array $attributes): int => Shift::query()
                ->whereKey($attributes['shift_id'])
                ->value('depot_id'),
            'shift_id' => $shift,
            'weekday' => fake()->numberBetween(1, 5),
            'duration_minutes' => 600,
            'is_active' => true,
        ];
    }
}
