<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'depot_id' => Depot::factory(),
            'code' => fake()->unique()->bothify('SHIFT-##'),
            'name' => fake()->randomElement(['Day Shift', 'Afternoon Shift', 'Night Shift']),
            'starts_at' => '06:00:00',
            'duration_minutes' => 600,
            'prestart_minutes' => 15,
            'cleaning_minutes' => 30,
            'performance_target_percent' => 90,
            'availability_target_percent' => 95,
            'is_active' => true,
        ];
    }
}
