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
            // Only the forecast's own gauge: a pipe consumes several, ordered separately.
            ->with(['wireRequirements' => fn ($query) => $query->where('wire_code', $forecast->wire_code)])
            ->get()
            ->keyBy('id');
        try {
            $demandLines = [];

            foreach ($validated['demand_lines'] as $line) {
                // A product with no requirement for this gauge simply contributes none of it.
                $demandLines = [...$demandLines, ...SteelDemandLine::forSku(
                    $validated['depot_id'],
                    $skus->get($line['sku_id']),
                    (int) $line['planned_quantity'],
                )];
            }

            // A pipe that does not use this gauge contributes none of it, which is a real answer. A
            // request where none of the products use it at all is a mismatched pairing, not a zero.
            if ($validated['demand_lines'] !== [] && $demandLines === []) {
                throw new InvalidArgumentException(sprintf(
                    'None of the supplied products consume %s.',
                    $forecast->wire_code,
                ));
            }

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
