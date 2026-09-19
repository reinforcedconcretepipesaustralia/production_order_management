<?php

namespace Database\Factories;

use App\Models\SkuMaster;
use App\Models\SkuWireRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SkuWireRequirement>
 */
class SkuWireRequirementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $wireCode = fake()->randomElement([
            'WIREPL3.97', 'WIREPL4.95', 'WIREPL6.00', 'WIREPL6.23',
            'WIREDE7.50', 'WIREDE9.00',
        ]);

        return [
            'sku_master_id' => SkuMaster::factory(),
            'wire_code' => $wireCode,
            'wire_gauge_mm' => SkuWireRequirement::gaugeFromWireCode($wireCode),
            'kg_per_pipe' => fake()->randomFloat(3, 1, 40),
        ];
    }

    public function forWireCode(string $wireCode, float $kgPerPipe): static
    {
        return $this->state(fn (): array => [
            'wire_code' => $wireCode,
            'wire_gauge_mm' => SkuWireRequirement::gaugeFromWireCode($wireCode),
            'kg_per_pipe' => $kgPerPipe,
        ]);
    }
}
