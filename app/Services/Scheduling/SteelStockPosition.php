<?php

namespace App\Services\Scheduling;

use App\Models\SteelForecast;
use InvalidArgumentException;

final readonly class SteelStockPosition
{
    public function __construct(
        public int $depotId,
        public string $wireCode,
        public int $onHandCoils,
        public int|float|string $averageCoilWeightTonnes,
        public int $onOrderCoils = 0,
        public int $inboundTransferCoils = 0,
        public int $outboundTransferCoils = 0,
        public int $safetyThresholdCoils = 0,
    ) {
        if ($depotId <= 0 || trim($wireCode) === '' || (float) $averageCoilWeightTonnes <= 0) {
            throw new InvalidArgumentException('Wire stock position requires a depot, wire code, and positive average coil weight.');
        }

        foreach ([$onHandCoils, $onOrderCoils, $inboundTransferCoils, $outboundTransferCoils, $safetyThresholdCoils] as $quantity) {
            if ($quantity < 0) {
                throw new InvalidArgumentException('Wire coil quantities cannot be negative.');
            }
        }
    }

    public function kilogramsPerCoil(): string
    {
        return number_format((float) $this->averageCoilWeightTonnes * 1_000, 3, '.', '');
    }

    public static function fromForecast(SteelForecast $forecast): self
    {
        return new self(
            depotId: $forecast->depot_id,
            wireCode: $forecast->wire_code,
            onHandCoils: $forecast->on_hand_coils,
            averageCoilWeightTonnes: $forecast->average_coil_weight_tonnes,
            onOrderCoils: $forecast->on_order_coils,
            inboundTransferCoils: $forecast->transfer_in_coils,
            outboundTransferCoils: $forecast->transfer_out_coils,
            safetyThresholdCoils: $forecast->safety_threshold_coils,
        );
    }
}
