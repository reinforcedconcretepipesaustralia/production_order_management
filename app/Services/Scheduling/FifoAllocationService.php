<?php

namespace App\Services\Scheduling;

use App\Models\Depot;
use App\Models\OrderAllocation;
use App\Models\ProductionSchedule;
use App\Models\SkuMaster;
use App\Services\Sync\OrderSync;
use App\Services\Sync\StockSync;
use Illuminate\Support\Facades\DB;

final class FifoAllocationService
{
    public function __construct(
        private OrderSync $orderBook,
        private StockSync $stock,
    ) {}

    public function allocatePrimeStock(Depot $depot, SkuMaster $sku): AllocationResult
    {
        return $this->allocateInventoryAndProduction($depot, $sku);
    }

    public function allocateInventoryAndProduction(Depot $depot, SkuMaster $sku): AllocationResult
    {
        return DB::transaction(function () use ($depot, $sku): AllocationResult {
            $orders = $this->orderBook->lines()
                ->filter(fn (array $line): bool => $line['depot_id'] === $depot->id
                    && $line['sku_master_id'] === $sku->id
                    && in_array($line['status'], ['open', 'partially_allocated'], true)
                    && $line['allocated_quantity'] < $line['ordered_quantity'])
                ->sortBy([
                    ['ordered_at', 'asc'],
                    ['requested_delivery_date', 'asc'],
                    ['erp_line_reference', 'asc'],
                ])
                ->values();

            $primeLocations = $this->stock->positions()
                ->filter(fn (array $row): bool => $row['depot_id'] === $depot->id
                    && $row['sku_master_id'] === $sku->id
                    && $row['kind'] === 'prime'
                    && $row['on_hand'] > 0)
                ->values();

            $alreadyTaken = OrderAllocation::query()
                ->where('depot_id', $depot->id)
                ->where('sku_master_id', $sku->id)
                ->where('status', 'allocated')
                ->whereNotNull('stock_location')
                ->get()
                ->groupBy('stock_location')
                ->map(fn ($group): int => (int) $group->sum('quantity'));

            $remainingStock = $primeLocations->map(function (array $row) use ($alreadyTaken): array {
                $row['available'] = max(0, $row['on_hand'] - (int) $alreadyTaken->get($row['stock_location'], 0));

                return $row;
            })->values()->all();

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

            $unfulfilledQuantity = $orders->sum(
                fn (array $order): int => max(0, $order['ordered_quantity'] - $order['allocated_quantity']),
            );
            $allocatedQuantity = 0;
            $allocationIds = [];
            $stockIndex = 0;
            /** @var array<int, int> $remainingScheduleQuantities */
            $remainingScheduleQuantities = $productionSchedules->mapWithKeys(
                fn (ProductionSchedule $schedule): array => [
                    $schedule->id => max(0, $schedule->planned_quantity - (int) $schedule->reserved_quantity),
                ],
            )->all();

            foreach ($orders as $order) {
                $remainingOrderQuantity = $order['ordered_quantity'] - $order['allocated_quantity'];

                while ($remainingOrderQuantity > 0 && isset($remainingStock[$stockIndex])) {
                    $available = $remainingStock[$stockIndex]['available'];

                    if ($available <= 0) {
                        $stockIndex++;

                        continue;
                    }

                    $quantity = min($remainingOrderQuantity, $available);
                    $remainingStock[$stockIndex]['available'] -= $quantity;

                    $allocation = OrderAllocation::query()->create([
                        'sales_order_id' => null,
                        'inventory_soh_id' => null,
                        'production_schedule_id' => null,
                        'depot_id' => $depot->id,
                        'sku_master_id' => $sku->id,
                        'erp_entity_code' => $order['erp_entity_code'],
                        'erp_order_reference' => $order['erp_order_reference'],
                        'erp_line_reference' => $order['erp_line_reference'],
                        'stock_location' => $remainingStock[$stockIndex]['stock_location'],
                        'quantity' => $quantity,
                        'allocated_at' => now(),
                        'status' => 'allocated',
                    ]);

                    $remainingOrderQuantity -= $quantity;
                    $allocatedQuantity += $quantity;
                    $allocationIds[] = $allocation->id;

                    if ($remainingStock[$stockIndex]['available'] === 0) {
                        $stockIndex++;
                    }
                }

                if ($remainingOrderQuantity > 0) {
                    foreach ($productionSchedules as $schedule) {
                        if ($schedule->schedule_date->gt($order['requested_delivery_date'])) {
                            break;
                        }

                        $availableQuantity = $remainingScheduleQuantities[$schedule->id];
                        if ($availableQuantity <= 0) {
                            continue;
                        }

                        $quantity = min($remainingOrderQuantity, $availableQuantity);
                        $allocation = OrderAllocation::query()->create([
                            'sales_order_id' => null,
                            'inventory_soh_id' => null,
                            'production_schedule_id' => $schedule->id,
                            'depot_id' => $depot->id,
                            'sku_master_id' => $sku->id,
                            'erp_entity_code' => $order['erp_entity_code'],
                            'erp_order_reference' => $order['erp_order_reference'],
                            'erp_line_reference' => $order['erp_line_reference'],
                            'stock_location' => null,
                            'quantity' => $quantity,
                            'allocated_at' => now(),
                            'status' => 'reserved',
                        ]);

                        $remainingScheduleQuantities[$schedule->id] -= $quantity;
                        $remainingOrderQuantity -= $quantity;
                        $allocatedQuantity += $quantity;
                        $allocationIds[] = $allocation->id;

                        if ($remainingOrderQuantity === 0) {
                            break;
                        }
                    }
                }
            }

            return new AllocationResult(
                allocatedQuantity: $allocatedQuantity,
                unfulfilledQuantity: $unfulfilledQuantity - $allocatedQuantity,
                allocationIds: $allocationIds,
            );
        }, 3);
    }
}
