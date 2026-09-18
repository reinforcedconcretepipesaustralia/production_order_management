<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\MachineAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MachineAsset>
 */
class MachineAssetFactory extends Factory
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
            'code' => fake()->unique()->bothify('MCH-###'),
            'name' => fake()->randomElement(['Hawkeye Line', 'Hydraulic Press', 'Cage Welder']),
            'machine_type' => fake()->randomElement(['pipe_machine', 'press', 'cage_welder']),
            'is_active' => true,
        ];
    }
}
