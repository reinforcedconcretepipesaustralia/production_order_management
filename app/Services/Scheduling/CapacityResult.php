<?php

namespace App\Services\Scheduling;

final readonly class CapacityResult
{
    /**
     * @param  string  $effectiveHours  Productive hours after performance and availability
     *                                  are applied — what the run rate is multiplied by.
     * @param  int  $plannedPipes  Rounded up, matching the workbooks' ROUNDUP(P * O, 0).
     * @param  string  $productiveHours  Shift hours less overheads and downtime, before OEE.
     *                                   This is the 'Effective hours' row on the OEE Settings tab.
     * @param  int  $availableMinutes  Minutes left in the shift after overheads and downtime.
     * @param  int  $downtimeMinutes  Minutes removed by downtime events.
     * @param  string  $runRatePerHour  The rate used, after any machine-specific override.
     * @param  string  $shiftFraction  Share of the shift this run occupies (Prod Plan column P).
     */
    public function __construct(
        public string $effectiveHours,
        public int $plannedPipes,
        public string $productiveHours = '0.000000',
        public int $availableMinutes = 0,
        public int $downtimeMinutes = 0,
        public string $runRatePerHour = '0.000',
        public string $shiftFraction = '1.0000',
    ) {}
}
