<?php

namespace App\Services\Logistics;

use App\Models\TransportCarrier;
use App\Models\TransportCarrierVehicle;
use App\Models\TransportCarrierZoneRate;

final class FreightEstimateService
{
    public function estimate(
        string $erpEntityCode,
        string $zoneCode,
        TransportCarrier $carrier,
        float $actualTonnes,
        ?TransportCarrierVehicle $vehicle = null,
    ): ?FreightEstimate {
        if ($carrier->is_pickup || $actualTonnes <= 0) {
            return new FreightEstimate(0, 0, 0, null, 0);
        }

        $zoneCode = trim($zoneCode);
        if ($zoneCode === '') {
            return null;
        }

        if (strcasecmp($zoneCode, 'ZZZNOFREIGH') === 0) {
            return new FreightEstimate($actualTonnes, $actualTonnes, 0, null, 0);
        }

        $rate = TransportCarrierZoneRate::query()
            ->where('erp_entity_code', $erpEntityCode)
            ->where('transport_carrier_id', $carrier->id)
            ->where('zone_code', $zoneCode)
            ->where('is_active', true)
            ->first();

        if ($rate === null) {
            return null;
        }

        $vehicle ??= $carrier->defaultVehicle();
        $minimumBillable = (float) ($vehicle?->minimum_billable_tonnes ?? 0);
        $billableTonnes = max($actualTonnes, $minimumBillable);
        $variable = $billableTonnes * (float) $rate->rate_per_tonne;
        $estimated = max((float) ($rate->minimum_charge_amount ?? 0), $variable);

        return new FreightEstimate(
            actualTonnes: $actualTonnes,
            billableTonnes: $billableTonnes,
            ratePerTonne: (float) $rate->rate_per_tonne,
            minimumChargeAmount: $rate->minimum_charge_amount !== null ? (float) $rate->minimum_charge_amount : null,
            estimatedAmount: round($estimated, 2),
        );
    }
}
