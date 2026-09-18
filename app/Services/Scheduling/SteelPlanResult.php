<?php

namespace App\Services\Scheduling;

final readonly class SteelPlanResult
{
    public function __construct(
        public int $depotId,
        public string $wireCode,
        public string $requiredKg,
        public string $requiredTonnes,
        public string $projectedEndingKg,
        public string $safetyStockKg,
        public string $recommendedOrderKg,
        public string $recommendedOrderTonnes,
    ) {}
}
