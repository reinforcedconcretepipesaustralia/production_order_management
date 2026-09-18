<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\InventorySoh;
use App\Models\SkuMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventorySoh>
 */
class InventorySohFactory extends Factory
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
            'sku_master_id' => SkuMaster::factory(),
            'bin_location' => fake()->unique()->bothify('YARD-??-##'),
            'prime_quantity' => fake()->numberBetween(0, 100),
            'rework_quantity' => fake()->numberBetween(0, 10),
            'quarantine_quantity' => fake()->numberBetween(0, 5),
            'counted_at' => now(),
        ];
    }
}
