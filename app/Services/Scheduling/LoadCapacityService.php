<?php

namespace App\Services\Scheduling;

use App\Exceptions\IneligibleLoadQuantityException;
use App\Exceptions\LoadCapacityExceededException;
use App\Models\DispatchLoad;
use App\Models\DispatchLoadItem;
use App\Models\OrderAllocation;
use App\Models\ProductionSchedule;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LoadCapacityService
{
    public function __construct(private readonly QualityCuringService $qualityCuringService) {}

    public function calculate(DispatchLoad $load, SalesOrder $order, int $quantity): LoadWeightResult
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Load item quantity must be greater than zero.');
        }

        $order->loadMissing('sku');
        $itemWeight = round((float) $order->sku->unit_weight_tonnes * $quantity, 4);
        $currentWeight = (float) $load->items()->sum('weight_tonnes');
        $loadWeight = $currentWeight + $itemWeight;
        $capacity = (float) $load->capacity_tonnes;

        if ($loadWeight - $capacity > 0.000_000_1) {
            throw new LoadCapacityExceededException(sprintf(
                'Adding %.4f tonnes would exceed load capacity %.3f tonnes.',
                $itemWeight,
                $capacity,
            ));
        }

        return new LoadWeightResult(
            itemWeightTonnes: number_format($itemWeight, 4, '.', ''),
            loadWeightTonnes: number_format($loadWeight, 4, '.', ''),
            remainingCapacityTonnes: number_format($capacity - $loadWeight, 4, '.', ''),
        );
    }

    public function addItem(DispatchLoad $load, OrderAllocation $allocation, int $quantity): LoadWeightResult
    {
        return DB::transaction(function () use ($load, $allocation, $quantity): LoadWeightResult {
            $lockedLoad = DispatchLoad::query()->lockForUpdate()->findOrFail($load->id);
            $lockedAllocation = OrderAllocation::query()
                ->with(['salesOrder.sku', 'productionSchedule.actuals', 'inventory'])
                ->lockForUpdate()
                ->findOrFail($allocation->id);
            $lockedOrder = $lockedAllocation->salesOrder;

            if ($lockedOrder->depot_id !== $lockedLoad->depot_id) {
                throw new IneligibleLoadQuantityException('Sales order depot does not match the dispatch load depot.');
            }

            $eligibleQuantity = $this->eligibleAllocationQuantity($lockedAllocation, $lockedLoad);
            $alreadyLoadedQuantity = DispatchLoadItem::query()
                ->where('order_allocation_id', $lockedAllocation->id)
                ->lockForUpdate()
                ->sum('quantity');

            if ($alreadyLoadedQuantity + $quantity > $eligibleQuantity) {
                throw new IneligibleLoadQuantityException(sprintf(
                    'Only %d pipes from this allocation remain dispatch eligible.',
                    max(0, $eligibleQuantity - $alreadyLoadedQuantity),
                ));
            }

            $weight = $this->calculate($lockedLoad, $lockedOrder, $quantity);
            $item = DispatchLoadItem::query()->firstOrNew([
                'dispatch_load_id' => $lockedLoad->id,
                'order_allocation_id' => $lockedAllocation->id,
            ]);
            $item->quantity = ($item->quantity ?? 0) + $quantity;
            $item->weight_tonnes = round((float) $lockedOrder->sku->unit_weight_tonnes * $item->quantity, 3);
            $item->save();

            $lockedLoad->current_weight_tonnes = $lockedLoad->items()->sum('weight_tonnes');
            $lockedLoad->save();

            return new LoadWeightResult(
                itemWeightTonnes: $weight->itemWeightTonnes,
                loadWeightTonnes: number_format((float) $lockedLoad->current_weight_tonnes, 3, '.', ''),
                remainingCapacityTonnes: number_format(
                    (float) $lockedLoad->capacity_tonnes - (float) $lockedLoad->current_weight_tonnes,
                    3,
                    '.',
                    '',
                ),
                item: $item,
            );
        }, 3);
    }

    public function removeItem(DispatchLoad $load, DispatchLoadItem $item): void
    {
        DB::transaction(function () use ($load, $item): void {
            $lockedLoad = DispatchLoad::query()->lockForUpdate()->findOrFail($load->id);
            $lockedItem = DispatchLoadItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($lockedItem->dispatch_load_id !== $lockedLoad->id) {
                throw new IneligibleLoadQuantityException('Dispatch item does not belong to the selected load.');
            }

            $lockedItem->delete();
            $lockedLoad->current_weight_tonnes = $lockedLoad->items()->sum('weight_tonnes');
            $lockedLoad->save();
        }, 3);
    }

    private function eligibleAllocationQuantity(OrderAllocation $allocation, DispatchLoad $load): int
    {
        if ($allocation->inventory_soh_id !== null && $allocation->status === 'allocated') {
            if (
                $allocation->inventory->depot_id !== $allocation->salesOrder->depot_id
                || $allocation->inventory->sku_master_id !== $allocation->salesOrder->sku_master_id
            ) {
                return 0;
            }

            return $allocation->quantity;
        }

        if ($allocation->production_schedule_id === null || $allocation->status !== 'reserved') {
            return 0;
        }

        /** @var ProductionSchedule $schedule */
        $schedule = $allocation->productionSchedule;
        if (
            $schedule->depot_id !== $allocation->salesOrder->depot_id
            || $schedule->sku_master_id !== $allocation->salesOrder->sku_master_id
        ) {
            return 0;
        }

        $releasedGoodQuantity = $schedule->actuals
            ->filter(fn ($actual): bool => $this->qualityCuringService
                ->evaluate($actual, $load->dispatch_date->endOfDay())
                ->isEligible)
            ->sum('good_quantity');

        $reservedBefore = OrderAllocation::query()
            ->where('production_schedule_id', $schedule->id)
            ->where('status', 'reserved')
            ->where(function ($query) use ($allocation): void {
                $query->where('allocated_at', '<', $allocation->allocated_at)
                    ->orWhere(function ($query) use ($allocation): void {
                        $query->where('allocated_at', $allocation->allocated_at)
                            ->where('id', '<', $allocation->id);
                    });
            })
            ->sum('quantity');

        return min(
            $allocation->quantity,
            max(0, $releasedGoodQuantity - $reservedBefore),
        );
    }
}
