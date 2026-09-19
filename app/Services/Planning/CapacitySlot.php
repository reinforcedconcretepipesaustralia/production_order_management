<?php

namespace App\Services\Planning;

use App\Models\Depot;
use App\Models\MachineAsset;
use App\Models\OeeSetting;
use App\Models\Shift;
use Carbon\CarbonImmutable;

/**
 * One machine, on one shift, on one day: the unit of capacity the planner fills.
 *
 * remainingFraction tracks how much of the shift is still unallocated, so several products
 * can share a shift the way 'Prod Plan' column P does.
 */
final class CapacitySlot
{
    private string $remainingFraction = '1.0000';

    public function __construct(
        public readonly Depot $depot,
        public readonly Shift $shift,
        public readonly MachineAsset $machine,
        public readonly CarbonImmutable $date,
        public readonly int $durationMinutes,
        public readonly int $downtimeMinutes,
        public readonly ?OeeSetting $oeeSetting,
    ) {}

    public function key(): string
    {
        return implode('|', [
            $this->depot->id,
            $this->machine->id,
            $this->shift->id,
            $this->date->toDateString(),
        ]);
    }

    public function remainingFraction(): string
    {
        return $this->remainingFraction;
    }

    public function hasCapacity(): bool
    {
        return bccomp($this->remainingFraction, '0', 4) > 0 && $this->productiveMinutes() > 0;
    }

    /**
     * Minutes left in the shift once overheads and downtime are removed. Zero means the
     * whole shift is consumed — a full maintenance day, for instance.
     */
    public function productiveMinutes(): int
    {
        $overheads = $this->oeeSetting?->nonProductiveMinutes()
            ?? (int) $this->shift->prestart_minutes + (int) $this->shift->cleaning_minutes;

        return max(0, $this->durationMinutes - $overheads - $this->downtimeMinutes);
    }

    /**
     * Claims a share of the shift, never more than what is left, and returns what was taken.
     */
    public function claim(string $fraction): string
    {
        $taken = bccomp($fraction, $this->remainingFraction, 4) > 0
            ? $this->remainingFraction
            : bcadd($fraction, '0', 4);

        $this->remainingFraction = bcsub($this->remainingFraction, $taken, 4);

        return $taken;
    }

    /**
     * Planned start and end for a run occupying the given share of the shift, measured from
     * where previous runs in this slot finished.
     */
    public function windowFor(string $claimedFraction, string $fractionAlreadyUsed): array
    {
        $start = $this->date
            ->setTimeFromTimeString((string) $this->shift->starts_at)
            ->addMinutes((int) bcmul((string) $this->durationMinutes, $fractionAlreadyUsed, 0));
        $end = $start->addMinutes((int) bcmul((string) $this->durationMinutes, $claimedFraction, 0));

        return [$start, $end];
    }
}
