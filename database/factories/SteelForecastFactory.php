<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\SteelForecast;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SteelForecast>
 */
class SteelForecastFactory extends Factory
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
            'forecast_date' => now()->toDateString(),
            'wire_code' => fake()->randomElement(['WIRE-500N', 'WIRE-500L']),
            'on_hand_coils' => fake()->numberBetween(2, 20),
            'average_coil_weight_tonnes' => fake()->randomFloat(4, 1.5, 3),
            'on_order_coils' => fake()->numberBetween(0, 10),
            'transfer_in_coils' => 0,
            'transfer_out_coils' => 0,
            'safety_threshold_coils' => 3,
        ];
    }
}
