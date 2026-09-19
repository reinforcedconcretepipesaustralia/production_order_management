<?php

namespace App\Services\Planning;

final readonly class ResolvedRunRate
{
    public const SOURCE_MACHINE = 'machine';

    public const SOURCE_SKU_STANDARD = 'sku_standard';

    public function __construct(
        public string $ratePerHour,
        public int $changeoverMinutes,
        public string $source,
    ) {}

    /**
     * True when a machine-specific rate exists, which is also how the planner knows the
     * machine is qualified to run this SKU at all.
     */
    public function isMachineSpecific(): bool
    {
        return $this->source === self::SOURCE_MACHINE;
    }
}
