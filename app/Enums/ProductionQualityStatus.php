<?php

namespace App\Enums;

enum ProductionQualityStatus: string
{
    case Curing = 'curing';
    case QualityHold = 'quality_hold';
    case Released = 'released';
    case Passed = 'passed';
    case Rework = 'rework';
    case Quarantined = 'quarantined';
    case Scrap = 'scrap';

    public function isDispatchEligible(): bool
    {
        return $this === self::Released || $this === self::Passed;
    }
}
