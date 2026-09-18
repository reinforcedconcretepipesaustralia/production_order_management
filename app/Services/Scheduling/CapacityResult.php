<?php

namespace App\Services\Scheduling;

final readonly class CapacityResult
{
    public function __construct(
        public string $effectiveHours,
        public int $plannedPipes,
    ) {}
}
