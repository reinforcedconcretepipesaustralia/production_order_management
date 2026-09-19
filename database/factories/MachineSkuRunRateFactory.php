<?php

namespace Database\Factories;

use App\Models\MachineAsset;
use App\Models\MachineSkuRunRate;
use App\Models\SkuMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MachineSkuRunRate>
 */
class MachineSkuRunRateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'machine_asset_id' => MachineAsset::factory(),
            'sku_master_id' => SkuMaster::factory(),
            'rated_per_hour' => fake()->numberBetween(8, 40),
            'adjustment_factor' => null,
            'changeover_minutes' => 0,
            'is_active' => true,
        ];
    }
}
