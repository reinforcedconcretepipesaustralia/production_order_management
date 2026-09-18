<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CalculationInputException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CalculateCapacityRequest;
use App\Http\Resources\Api\V1\CapacityCalculationResource;
use App\Models\Shift;
use App\Models\SkuMaster;
use App\Services\Scheduling\ShiftCapacityCalculator;
use InvalidArgumentException;

class CapacityCalculationController extends Controller
{
    public function __invoke(
        CalculateCapacityRequest $request,
        ShiftCapacityCalculator $calculator,
    ): CapacityCalculationResource {
        try {
            $result = $calculator->calculate(
                Shift::query()->findOrFail($request->integer('shift_id')),
                SkuMaster::query()->findOrFail($request->integer('sku_id')),
            );
        } catch (InvalidArgumentException $exception) {
            throw new CalculationInputException($exception);
        }

        return new CapacityCalculationResource($result);
    }
}
