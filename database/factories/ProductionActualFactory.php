<?php

namespace Database\Factories;

use App\Enums\ProductionQualityStatus;
use App\Models\ProductionActual;
use App\Models\ProductionSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionActual>
 */
class ProductionActualFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_schedule_id' => ProductionSchedule::factory(),
            'manufactured_at' => now(),
            'good_quantity' => fake()->numberBetween(5, 80),
            'dumped_quantity' => 0,
            'dump_reason' => null,
            'actual_run_rate_per_hour' => fake()->randomFloat(3, 2, 20),
            'quality_percent' => fake()->randomFloat(2, 90, 100),
            'quality_status' => ProductionQualityStatus::Curing,
            'qc_released_at' => null,
            'qc_released_by' => null,
            'notes' => null,
        ];
    }
}
