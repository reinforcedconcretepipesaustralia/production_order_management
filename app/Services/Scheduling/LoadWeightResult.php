<?php

namespace App\Services\Scheduling;

use App\Models\DispatchLoadItem;

final readonly class LoadWeightResult
{
    public function __construct(
        public string $itemWeightTonnes,
        public string $loadWeightTonnes,
        public string $remainingCapacityTonnes,
        public ?DispatchLoadItem $item = null,
    ) {}
}
