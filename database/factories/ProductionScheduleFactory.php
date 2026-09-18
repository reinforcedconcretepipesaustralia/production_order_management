<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\MachineAsset;
use App\Models\MouldAsset;
use App\Models\ProductionSchedule;
use App\Models\Shift;
use App\Models\SkuMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionSchedule>
 */
class ProductionScheduleFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (ProductionSchedule $schedule): void {
            $schedule->shift()->update(['depot_id' => $schedule->depot_id]);
            $schedule->machine()->update(['depot_id' => $schedule->depot_id]);
            $schedule->mould()->update(['current_depot_id' => $schedule->depot_id]);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'depot_id' => Depot::factory(),
            'shift_id' => Shift::factory(),
            'machine_asset_id' => MachineAsset::factory(),
            'sku_master_id' => SkuMaster::factory(),
            'mould_asset_id' => MouldAsset::factory(),
            'schedule_date' => fake()->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
            'planned_start_at' => now()->addDay()->setTime(6, 0),
            'planned_end_at' => now()->addDay()->setTime(14, 0),
            'planned_quantity' => fake()->numberBetween(10, 100),
            'planned_oee_percent' => 85,
            'effective_hours' => 7.25,
            'status' => 'draft',
            'changeover_minutes' => 30,
            'changeover_from_sku_code' => null,
            'changeover_notes' => null,
        ];
    }
}
