<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\DispatchLoad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DispatchLoad>
 */
class DispatchLoadFactory extends Factory
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
            'load_reference' => fake()->unique()->bothify('LOAD-######'),
            'dispatch_date' => fake()->dateTimeBetween('now', '+14 days')->format('Y-m-d'),
            'capacity_tonnes' => 24,
            'current_weight_tonnes' => 0,
            'status' => 'planned',
            'notification_status' => 'pending',
            'notified_at' => null,
        ];
    }
}
