<?php

namespace Database\Factories;

use App\Models\SkuMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SkuMaster>
 */
class SkuMasterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku_code' => fake()->unique()->bothify('RCP-###-##'),
            'description' => fake()->randomElement(['Reinforced concrete pipe', 'Stormwater pipe']),
            'diameter_mm' => fake()->randomElement([300, 450, 600, 750]),
            'length_mm' => 2400,
            'unit_weight_tonnes' => fake()->randomFloat(4, 0.3, 6),
            'wire_code' => fake()->randomElement(['WIRE-500N', 'WIRE-500L']),
            'wire_kg_per_pipe' => fake()->randomFloat(3, 20, 500),
            'concrete_volume_m3' => fake()->randomFloat(4, 0.1, 2),
            'standard_run_rate_per_hour' => fake()->randomFloat(3, 2, 20),
            'bom_reference' => fake()->bothify('BOM-####'),
            'is_active' => true,
        ];
    }
}
