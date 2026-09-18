<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Scheduling\AllocationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AllocationResult */
class FifoAllocationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'allocated_quantity' => $this->allocatedQuantity,
            'unfulfilled_quantity' => $this->unfulfilledQuantity,
            'allocation_ids' => $this->allocationIds,
        ];
    }
}
