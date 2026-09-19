<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\NonProductionDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NonProductionDay>
 */
class NonProductionDayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'depot_id' => Depot::factory(),
            'date' => fake()->dateTimeBetween('now', '+3 months')->format('Y-m-d'),
            'category' => 'holiday',
            'reason' => fake()->randomElement(['Public holiday', 'Christmas shutdown', 'Stocktake']),
        ];
    }
}
