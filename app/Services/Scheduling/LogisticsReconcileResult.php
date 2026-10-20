<?php

namespace App\Services\Scheduling;

final readonly class LogisticsReconcileResult
{
    /**
     * @param  list<array{depot_id: int, sku_master_id: int, allocated_quantity: int, unfulfilled_quantity: int}>  $pairs
     */
    public function __construct(
        public int $pairCount,
        public int $deletedAllocationRows,
        public int $totalAllocatedQuantity,
        public int $totalUnfulfilledQuantity,
        public bool $dryRun,
        public array $pairs = [],
    ) {}
}
