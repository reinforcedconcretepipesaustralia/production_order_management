<?php

namespace App\Services\Logistics;

final readonly class FreightEstimate
{
    public function __construct(
        public float $actualTonnes,
        public float $billableTonnes,
        public float $ratePerTonne,
        public ?float $minimumChargeAmount,
        public float $estimatedAmount,
    ) {}
}
