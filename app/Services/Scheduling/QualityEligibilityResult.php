<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;

final readonly class QualityEligibilityResult
{
    public function __construct(
        public CarbonImmutable $releaseDate,
        public string $status,
        public bool $isEligible,
    ) {}
}
