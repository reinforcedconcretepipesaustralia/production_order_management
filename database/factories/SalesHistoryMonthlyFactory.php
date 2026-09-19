<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\SalesHistoryMonthly;
use App\Models\SkuMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesHistoryMonthly>
 */
class SalesHistoryMonthlyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'depot_id' => Depot::factory(),
            'sku_master_id' => SkuMaster::factory(),
            'month' => now()->startOfMonth()->subMonth()->toDateString(),
            'quantity_ea' => fake()->numberBetween(0, 400),
            'tonnes' => fake()->randomFloat(4, 0, 200),
            'net_value_ex_tax' => fake()->randomFloat(2, 0, 200_000),
        ];
    }

    public function forMonth(string $month, int $quantityEa): static
    {
        return $this->state(fn (): array => [
            'month' => $month,
            'quantity_ea' => $quantityEa,
        ]);
    }
}
