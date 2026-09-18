<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Scheduling\SteelPlanResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SteelPlanResult */
class SteelReplenishmentCalculationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'depot_id' => $this->depotId,
            'wire_code' => $this->wireCode,
            'required_kg' => $this->requiredKg,
            'required_tonnes' => $this->requiredTonnes,
            'projected_ending_kg' => $this->projectedEndingKg,
            'safety_stock_kg' => $this->safetyStockKg,
            'recommended_order_kg' => $this->recommendedOrderKg,
            'recommended_order_tonnes' => $this->recommendedOrderTonnes,
        ];
    }
}
