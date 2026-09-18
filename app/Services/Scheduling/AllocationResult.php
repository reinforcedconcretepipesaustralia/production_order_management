<?php

namespace App\Services\Scheduling;

final readonly class AllocationResult
{
    /**
     * @param  list<int>  $allocationIds
     */
    public function __construct(
        public int $allocatedQuantity,
        public int $unfulfilledQuantity,
        public array $allocationIds,
    ) {}
}
