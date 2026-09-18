<?php

namespace App\Services\Scheduling;

use App\Models\Depot;
use App\Models\InventorySoh;
use App\Models\OrderAllocation;
use App\Models\ProductionSchedule;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use Illuminate\Support\Facades\DB;

final class FifoAllocationService
{
    public function allocatePrimeStock(Depot $depot, SkuMaster $sku): AllocationResult
    {
        return $this->allocateInventoryAndProduction($depot, $sku);
    }

    public function allocateInventoryAndProduction(Depot $depot, SkuMaster $sku): AllocationResult
    {
        return DB::transaction(function () use ($depot, $sku): AllocationResult {
            $inventoryRows = InventorySoh::query()
                ->whereBelongsTo($depot)
                ->whereBelongsTo($sku, 'sku')
                ->where('prime_quantity', '>', 0)
                ->orderBy('counted_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $productionSchedules = ProductionSchedule::query()
                ->withSum([
                    'orderAllocations as reserved_quantity' => fn ($query) => $query
                        ->whereIn('status', ['reserved', 'allocated']),
                ], 'quantity')
                ->whereBelongsTo($depot)
                ->whereBelongsTo($sku, 'sku')
                ->whereIn('status', ['draft', 'planned', 'scheduled', 'confirmed'])
                ->where('planned_quantity', '>', 0)
                ->orderBy('schedule_date')
                ->orderBy('planned_start_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $orders = SalesOrder::query()
                ->whereBelongsTo($depot)
                ->whereBelongsTo($sku, 'sku')
                ->whereIn('status', ['open', 'partially_allocated'])
                ->whereColumn('allocated_quantity', '<', 'ordered_quantity')
                ->orderBy('ordered_at')
                ->orderBy('requested_delivery_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $unfulfilledQuantity = $orders->sum(
                fn (SalesOrder $order): int => max(0, $order->ordered_quantity - $order->allocated_quantity),
            );
            $allocatedQuantity = 0;
            $allocationIds = [];
            $inventoryIndex = 0;
            /** @var array<int, int> $remainingScheduleQuantities */
            $remainingScheduleQuantities = $productionSchedules->mapWithKeys(
                fn (ProductionSchedule $schedule): array => [
                    $schedule->id => max(0, $schedule->planned_quantity - (int) $schedule->reserved_quantity),
                ],
            )->all();

            foreach ($orders as $order) {
                $remainingOrderQuantity = $order->ordered_quantity - $order->allocated_quantity;

                while ($remainingOrderQuantity > 0 && isset($inventoryRows[$inventoryIndex])) {
                    /** @var InventorySoh $inventory */
                    $inventory = $inventoryRows[$inventoryIndex];

                    if ($inventory->prime_quantity <= 0) {
                        $inventoryIndex++;

                        continue;
                    }

                    $quantity = min($remainingOrderQuantity, $inventory->prime_quantity);
                    $inventory->prime_quantity -= $quantity;
                    $inventory->save();

                    $allocation = OrderAllocation::query()->create([
                        'sales_order_id' => $order->id,
                        'inventory_soh_id' => $inventory->id,
                        'production_schedule_id' => null,
                        'quantity' => $quantity,
                        'allocated_at' => now(),
                        'status' => 'allocated',
                    ]);

                    $order->allocated_quantity += $quantity;
                    $remainingOrderQuantity -= $quantity;
                    $allocatedQuantity += $quantity;
                    $allocationIds[] = $allocation->id;

                    if ($inventory->prime_quantity === 0) {
                        $inventoryIndex++;
                    }
                }

                if ($remainingOrderQuantity > 0) {
                    foreach ($productionSchedules as $schedule) {
                        if ($schedule->schedule_date->gt($order->requested_delivery_date)) {
                            break;
                        }

                        $availableQuantity = $remainingScheduleQuantities[$schedule->id];
                        if ($availableQuantity <= 0) {
                            continue;
                        }

                        $quantity = min($remainingOrderQuantity, $availableQuantity);
                        $allocation = OrderAllocation::query()->create([
                            'sales_order_id' => $order->id,
                            'inventory_soh_id' => null,
                            'production_schedule_id' => $schedule->id,
                            'quantity' => $quantity,
                            'allocated_at' => now(),
                            'status' => 'reserved',
                        ]);

                        $remainingScheduleQuantities[$schedule->id] -= $quantity;
                        $order->allocated_quantity += $quantity;
                        $remainingOrderQuantity -= $quantity;
                        $allocatedQuantity += $quantity;
                        $allocationIds[] = $allocation->id;

                        if ($remainingOrderQuantity === 0) {
                            break;
                        }
                    }
                }

                $order->status = match (true) {
                    $order->allocated_quantity >= $order->ordered_quantity => 'allocated',
                    $order->allocated_quantity > 0 => 'partially_allocated',
                    default => $order->status,
                };
                $order->save();

            }

            return new AllocationResult(
                allocatedQuantity: $allocatedQuantity,
                unfulfilledQuantity: $unfulfilledQuantity - $allocatedQuantity,
                allocationIds: $allocationIds,
            );
        }, 3);
    }
}
