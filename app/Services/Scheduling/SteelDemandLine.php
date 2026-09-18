<?php

namespace App\Services\Scheduling;

use App\Models\SkuMaster;
use InvalidArgumentException;

final readonly class SteelDemandLine
{
    public function __construct(
        public int $depotId,
        public SkuMaster $sku,
        public int $plannedQuantity,
    ) {
        if (
            $depotId <= 0
            || trim((string) $sku->wire_code) === ''
            || (float) $sku->wire_kg_per_pipe < 0
            || $plannedQuantity < 0
        ) {
            throw new InvalidArgumentException('Wire demand requires a depot, persisted SKU wire values, and non-negative planned quantity.');
        }
    }

    public function wireCode(): string
    {
        return $this->sku->wire_code;
    }

    public function requiredKilograms(): string
    {
        return number_format($this->plannedQuantity * (float) $this->sku->wire_kg_per_pipe, 3, '.', '');
    }
}
