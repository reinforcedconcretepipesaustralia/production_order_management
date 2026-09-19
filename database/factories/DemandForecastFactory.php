<?php

namespace Database\Factories;

use App\Models\DemandForecast;
use App\Models\Depot;
use App\Models\SkuMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandForecast>
 */
class DemandForecastFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $r3m = fake()->randomFloat(4, 0, 300);

        return [
            'depot_id' => Depot::factory(),
            'sku_master_id' => SkuMaster::factory(),
            'as_of_date' => now()->toDateString(),
            'r3m_monthly_ea' => $r3m,
            'r6m_monthly_ea' => $r3m,
            'r12m_monthly_ea' => $r3m,
            'outstanding_qty' => fake()->numberBetween(0, 800),
            'overdue_qty' => 0,
            'current_month_qty' => fake()->numberBetween(0, 400),
            'outstanding_by_month' => [],
            'earliest_due_date' => null,
            'prime_soh_qty' => fake()->numberBetween(0, 400),
            'rework_soh_qty' => fake()->numberBetween(0, 50),
            'net_position_qty' => 0,
            'months_on_hand' => null,
            'equivalent_months' => null,
            'has_demand_signal' => false,
            'abc_class' => 'A',
            'abc_cumulative_share' => 0.5,
            'order_backed_qty' => 0,
            'replenishment_qty' => 0,
        ];
    }
}
