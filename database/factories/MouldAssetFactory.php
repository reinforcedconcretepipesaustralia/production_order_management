<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\MouldAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MouldAsset>
 */
class MouldAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'current_depot_id' => Depot::factory(),
            'code' => fake()->unique()->bothify('MLD-####'),
            'diameter_mm' => fake()->randomElement([300, 375, 450, 525, 600, 750, 900]),
            'mould_type' => fake()->randomElement(['flush_joint', 'rubber_ring_joint']),
            'is_shared' => fake()->boolean(25),
            'machine_head_constraint' => fake()->optional()->bothify('HEAD-##'),
            'shared_component_constraint' => fake()->optional()->bothify('CORE-##'),
            'is_active' => true,
        ];
    }
}
