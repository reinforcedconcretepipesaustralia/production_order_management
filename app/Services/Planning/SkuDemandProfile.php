<?php

namespace App\Services\Planning;

use App\Models\SkuMaster;
use Carbon\CarbonImmutable;

/**
 * The demand picture for one SKU at one plant on one day — a row of the 'Pipes to Manufacture'
 * sheet, computed rather than recalculated by formula.
 */
final readonly class SkuDemandProfile
{
    /**
     * @param  array<string, int>  $outstandingByMonth  Delivery month (first of month) to eaches.
     */
    public function __construct(
        public int $depotId,
        public SkuMaster $sku,
        public CarbonImmutable $asOf,
        public float $r3mMonthlyEa,
        public float $r6mMonthlyEa,
        public float $r12mMonthlyEa,
        public int $outstandingQty,
        public int $overdueQty,
        public int $currentMonthQty,
        public array $outstandingByMonth,
        public ?CarbonImmutable $earliestDueDate,
        public int $primeSohQty,
        public int $reworkSohQty,
        public ?string $abcClass = null,
        public ?float $abcCumulativeShare = null,
    ) {}

    /**
     * Prime stock less the whole order book. Negative means the order book is uncovered.
     * Workbook column AA.
     */
    public function netPositionQty(): int
    {
        return $this->primeSohQty - $this->outstandingQty;
    }

    /**
     * How many months the prime stock on hand would last at the recent rate. Workbook column AJ.
     */
    public function monthsOnHand(): ?float
    {
        return $this->r3mMonthlyEa > 0 ? $this->primeSohQty / $this->r3mMonthlyEa : null;
    }

    /**
     * Order book expressed in months of normal sales. Workbook column U — above three it
     * suggests demand for this SKU is rising and Sales should be asked why.
     */
    public function equivalentMonths(): ?float
    {
        return $this->r12mMonthlyEa > 0 ? $this->outstandingQty / $this->r12mMonthlyEa : null;
    }

    public function hasDemandSignal(): bool
    {
        return ($this->equivalentMonths() ?? 0.0) > 3.0;
    }

    /**
     * What must be made to cover orders that prime stock cannot. This is the only requirement
     * the plant works on until the order book is covered.
     */
    public function orderBackedQty(): int
    {
        return max(0, $this->outstandingQty - $this->primeSohQty);
    }

    public function hasOrderBackedDemand(): bool
    {
        return $this->orderBackedQty() > 0;
    }

    public function isFastMover(): bool
    {
        return $this->abcClass === 'A';
    }

    /**
     * Safety stock for a fast mover, over and above the order book — built only once demand is
     * caught up. Slower movers are made to order and never carry a target.
     */
    public function replenishmentQty(float $defaultTargetMonthsOnHand): int
    {
        if (! $this->isFastMover()) {
            return 0;
        }

        $target = $this->sku->target_months_on_hand !== null
            ? (float) $this->sku->target_months_on_hand
            : $defaultTargetMonthsOnHand;

        if ($target <= 0 || $this->r3mMonthlyEa <= 0) {
            return 0;
        }

        // Stock left once the order book is served is what counts towards the target.
        $availableAfterOrders = max(0, $this->netPositionQty());

        return max(0, (int) ceil(($target * $this->r3mMonthlyEa) - $availableAfterOrders));
    }

    /**
     * The 'to make' figure the workbook shows in column AD: what this month's orders need that
     * stock cannot cover.
     */
    public function toMakeThisMonthQty(): int
    {
        return max(0, $this->currentMonthQty - $this->primeSohQty);
    }
}
