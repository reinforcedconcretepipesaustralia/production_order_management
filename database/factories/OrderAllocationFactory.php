<?php

namespace Database\Factories;

use App\Models\InventorySoh;
use App\Models\OrderAllocation;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderAllocation> */
class OrderAllocationFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (OrderAllocation $allocation): void {
            $allocation->loadMissing('salesOrder');

            $allocation->inventory?->update([
                'depot_id' => $allocation->salesOrder->depot_id,
                'sku_master_id' => $allocation->salesOrder->sku_master_id,
            ]);
            $allocation->productionSchedule?->update([
                'depot_id' => $allocation->salesOrder->depot_id,
                'sku_master_id' => $allocation->salesOrder->sku_master_id,
            ]);
        });
    }

    public function definition(): array
    {
        return [
            'sales_order_id' => SalesOrder::factory(),
            'inventory_soh_id' => InventorySoh::factory(),
            'production_schedule_id' => null,
            'quantity' => fake()->numberBetween(1, 20),
            'allocated_at' => now(),
            'status' => 'allocated',
        ];
    }
}
