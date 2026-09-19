<?php

namespace App\Services\Planning;

use App\Models\SkuMaster;
use Carbon\CarbonImmutable;

/**
 * A requirement as the planner works through it: the demand line plus how much of it is still
 * unmet as capacity is consumed.
 *
 * Mutable on purpose. DemandRequirement is the immutable statement of what is needed; this is
 * the planner's working copy of it.
 */
final class PlannedRequirement
{
    public int $remaining;

    public function __construct(
        public readonly DemandRequirement $demand,
        public readonly int $rank,
    ) {
        $this->remaining = $demand->quantity;
    }

    public function sku(): SkuMaster
    {
        return $this->demand->sku;
    }

    public function isOutstanding(): bool
    {
        return $this->remaining > 0;
    }

    public function isOrderBacked(): bool
    {
        return $this->demand->isOrderBacked();
    }

    public function dueDate(): ?CarbonImmutable
    {
        return $this->demand->dueDate;
    }

    /**
     * Requirements sharing this key are equally urgent, so the planner is free to prefer whatever
     * is already set up on the machine rather than paying for a change-over.
     */
    public function urgencyKey(): string
    {
        return $this->demand->source.'|'.($this->demand->dueDate?->toDateString() ?? '-');
    }

    public function take(int $quantity): int
    {
        $taken = min($quantity, $this->remaining);
        $this->remaining -= $taken;

        return $taken;
    }
}
