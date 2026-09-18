<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CalculationInputException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CalculateSteelReplenishmentRequest;
use App\Http\Resources\Api\V1\SteelReplenishmentCalculationResource;
use App\Models\SkuMaster;
use App\Models\SteelForecast;
use App\Services\Scheduling\SteelDemandLine;
use App\Services\Scheduling\SteelPlanningService;
use App\Services\Scheduling\SteelStockPosition;
use InvalidArgumentException;

class SteelReplenishmentCalculationController extends Controller
{
    public function __invoke(
        CalculateSteelReplenishmentRequest $request,
        SteelPlanningService $planner,
    ): SteelReplenishmentCalculationResource {
        $validated = $request->validated();
        $forecast = SteelForecast::query()->findOrFail($validated['steel_forecast_id']);
        $skus = SkuMaster::query()
            ->whereKey(array_column($validated['demand_lines'], 'sku_id'))
            ->get()
            ->keyBy('id');
        try {
            $demandLines = array_map(
                fn (array $line): SteelDemandLine => new SteelDemandLine(
                    depotId: $validated['depot_id'],
                    sku: $skus->get($line['sku_id']),
                    plannedQuantity: $line['planned_quantity'],
                ),
                $validated['demand_lines'],
            );
            $result = $planner->calculate(
                $demandLines,
                SteelStockPosition::fromForecast($forecast),
                $validated['rolling_weekly_consumption_kg'] ?? [],
            );
        } catch (InvalidArgumentException $exception) {
            throw new CalculationInputException($exception);
        }

        return new SteelReplenishmentCalculationResource($result);
    }
}
