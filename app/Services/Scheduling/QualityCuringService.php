<?php

namespace App\Services\Scheduling;

use App\Enums\ProductionQualityStatus;
use App\Models\ProductionActual;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class QualityCuringService
{
    public function evaluate(ProductionActual $actual, CarbonInterface|string|null $at = null): QualityEligibilityResult
    {
        if ($actual->manufactured_at === null) {
            throw new \InvalidArgumentException('Production actual must have a manufacture time.');
        }

        $releaseDate = CarbonImmutable::parse($actual->manufactured_at)->addDays(7);
        $evaluatedAt = $at === null ? CarbonImmutable::now() : CarbonImmutable::parse($at);

        if ($evaluatedAt->lt($releaseDate)) {
            return new QualityEligibilityResult($releaseDate, 'Curing', false);
        }

        $hasReleaseSignOff = $actual->qc_released_at !== null
            && filled($actual->qc_released_by)
            && CarbonImmutable::parse($actual->qc_released_at)->lte($evaluatedAt);

        if ($actual->quality_status->isDispatchEligible() && $hasReleaseSignOff) {
            return new QualityEligibilityResult($releaseDate, 'Released', true);
        }

        $status = match ($actual->quality_status) {
            ProductionQualityStatus::Rework => 'Rework',
            ProductionQualityStatus::Quarantined => 'Quarantined',
            ProductionQualityStatus::Scrap => 'Scrap',
            default => 'Quality Hold',
        };

        return new QualityEligibilityResult($releaseDate, $status, false);
    }
}
