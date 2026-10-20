<?php

namespace App\Services\Scheduling;

use App\Models\Depot;
use App\Models\OrderAllocation;
use App\Models\ProductionSchedule;
use App\Models\SkuMaster;
use App\Services\Sync\OrderSync;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LogisticsReconcileService
{
    public function __construct(
        private OrderSync $orderBook,
        private FifoAllocationService $allocator,
    ) {}

    public function reconcile(?int $depotId = null, ?int $skuId = null, bool $dryRun = false): LogisticsReconcileResult
    {
        $pairs = $this->discoverPairs($depotId, $skuId);
        $deleted = 0;
        $totalAllocated = 0;
        $totalUnfulfilled = 0;
        $pairResults = [];

        foreach ($pairs as $pair) {
            if ($dryRun) {
                continue;
            }

            $depot = Depot::query()->findOrFail($pair['depot_id']);
            $sku = SkuMaster::query()->findOrFail($pair['sku_master_id']);

            [$deletedForPair, $result] = DB::transaction(function () use ($depot, $sku): array {
                $deletedForPair = $this->deleteRebuildableAllocations($depot->id, $sku->id);
                $result = $this->allocator->allocateInventoryAndProduction($depot, $sku);

                return [$deletedForPair, $result];
            }, 3);

            $deleted += $deletedForPair;
            $totalAllocated += $result->allocatedQuantity;
            $totalUnfulfilled += $result->unfulfilledQuantity;
            $pairResults[] = [
                'depot_id' => $depot->id,
                'sku_master_id' => $sku->id,
                'allocated_quantity' => $result->allocatedQuantity,
                'unfulfilled_quantity' => $result->unfulfilledQuantity,
            ];
        }

        return new LogisticsReconcileResult(
            pairCount: $pairs->count(),
            deletedAllocationRows: $deleted,
            totalAllocatedQuantity: $totalAllocated,
            totalUnfulfilledQuantity: $totalUnfulfilled,
            dryRun: $dryRun,
            pairs: $pairResults,
        );
    }

    /**
     * @return Collection<int, array{depot_id: int, sku_master_id: int}>
     */
    private function discoverPairs(?int $depotId, ?int $skuId): Collection
    {
        $pairs = collect();

        foreach ($this->orderBook->lines() as $line) {
            if ($depotId !== null && (int) $line['depot_id'] !== $depotId) {
                continue;
            }

            if ($skuId !== null && (int) $line['sku_master_id'] !== $skuId) {
                continue;
            }

            if (! in_array($line['status'], ['open', 'partially_allocated'], true)) {
                continue;
            }

            $pairs->push([
                'depot_id' => (int) $line['depot_id'],
                'sku_master_id' => (int) $line['sku_master_id'],
            ]);
        }

        $statuses = config('logistics.production_commit_statuses', ['scheduled', 'in_progress']);

        ProductionSchedule::query()
            ->whereIn('status', $statuses)
            ->where('planned_quantity', '>', 0)
            ->when($depotId !== null, fn ($query) => $query->where('depot_id', $depotId))
            ->when($skuId !== null, fn ($query) => $query->where('sku_master_id', $skuId))
            ->get(['depot_id', 'sku_master_id'])
            ->each(function (ProductionSchedule $schedule) use ($pairs): void {
                $pairs->push([
                    'depot_id' => (int) $schedule->depot_id,
                    'sku_master_id' => (int) $schedule->sku_master_id,
                ]);
            });

        return $pairs
            ->unique(fn (array $pair): string => $pair['depot_id'].'|'.$pair['sku_master_id'])
            ->values();
    }

    private function deleteRebuildableAllocations(int $depotId, int $skuId): int
    {
        return OrderAllocation::query()
            ->where('depot_id', $depotId)
            ->where('sku_master_id', $skuId)
            ->whereIn('status', ['allocated', 'reserved'])
            ->whereDoesntHave('dispatchItems')
            ->delete();
    }
}
