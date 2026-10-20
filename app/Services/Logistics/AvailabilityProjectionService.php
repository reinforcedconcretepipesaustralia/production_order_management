<?php

namespace App\Services\Logistics;

use App\Models\Depot;
use App\Models\OrderAllocation;
use App\Models\SkuMaster;
use App\Services\Scheduling\LoadCapacityService;
use App\Services\Sync\OrderSync;
use App\Services\Sync\StockSync;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * @phpstan-type AvailabilityRow array{
 *     sku_master_id: int,
 *     sku_code: string,
 *     prime_soh: int,
 *     open_order_qty: int,
 *     allocated_qty: int,
 *     dispatch_ready_qty: int,
 * }
 */
final class AvailabilityProjectionService
{
    public function __construct(
        private StockSync $stock,
        private OrderSync $orders,
        private LoadCapacityService $loads,
    ) {}

    /**
     * @return Collection<int, AvailabilityRow>
     */
    public function rowsForDepot(Depot $depot, CarbonInterface $dispatchDate): Collection
    {
        $entity = (string) $depot->erp_entity_code;

        $skus = SkuMaster::query()
            ->where('erp_entity_code', $entity)
            ->orderBy('sku_code')
            ->get(['id', 'sku_code']);

        $openBySku = $this->orders->lines()
            ->filter(fn (array $line): bool => (int) $line['depot_id'] === $depot->id)
            ->groupBy('sku_master_id')
            ->map(fn (Collection $lines): int => (int) $lines->sum(
                fn (array $line): int => max(0, (int) $line['ordered_quantity'] - (int) $line['allocated_quantity']),
            ));

        $stockBySku = $this->stock->forDepot($depot)
            ->mapWithKeys(fn (array $row, int $skuId): array => [$skuId => (int) $row['prime']]);

        $allocatedBySku = OrderAllocation::query()
            ->where('depot_id', $depot->id)
            ->whereIn('status', ['allocated', 'reserved'])
            ->selectRaw('sku_master_id, sum(quantity) as total')
            ->groupBy('sku_master_id')
            ->pluck('total', 'sku_master_id');

        $dispatchReadyBySku = OrderAllocation::query()
            ->where('depot_id', $depot->id)
            ->whereIn('status', ['allocated', 'reserved'])
            ->with(['salesOrder.sku', 'sku', 'productionSchedule.actuals', 'inventory'])
            ->get()
            ->groupBy('sku_master_id')
            ->map(function (Collection $allocations) use ($dispatchDate): int {
                return (int) $allocations->sum(
                    fn (OrderAllocation $allocation): int => $this->loads->dispatchReadyQuantity($allocation, $dispatchDate),
                );
            });

        return $skus->map(function (SkuMaster $sku) use (
            $openBySku,
            $stockBySku,
            $allocatedBySku,
            $dispatchReadyBySku,
        ): array {
            return [
                'sku_master_id' => $sku->id,
                'sku_code' => $sku->sku_code,
                'prime_soh' => (int) ($stockBySku[$sku->id] ?? 0),
                'open_order_qty' => (int) ($openBySku[$sku->id] ?? 0),
                'allocated_qty' => (int) ($allocatedBySku[$sku->id] ?? 0),
                'dispatch_ready_qty' => (int) ($dispatchReadyBySku[$sku->id] ?? 0),
            ];
        })->filter(fn (array $row): bool => $row['open_order_qty'] > 0
            || $row['allocated_qty'] > 0
            || $row['prime_soh'] > 0
            || $row['dispatch_ready_qty'] > 0);
    }
}
