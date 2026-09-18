<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AllocateFifoRequest;
use App\Http\Resources\Api\V1\FifoAllocationResource;
use App\Models\Depot;
use App\Models\SkuMaster;
use App\Services\Scheduling\FifoAllocationService;

class FifoAllocationController extends Controller
{
    public function __invoke(
        AllocateFifoRequest $request,
        FifoAllocationService $allocator,
    ): FifoAllocationResource {
        $result = $allocator->allocateInventoryAndProduction(
            Depot::query()->findOrFail($request->integer('depot_id')),
            SkuMaster::query()->findOrFail($request->integer('sku_id')),
        );

        return new FifoAllocationResource($result);
    }
}
