<?php

namespace App\Services\Planning;

use Illuminate\Support\Collection;

/**
 * Turns demand profiles into the ranked list of things to make.
 *
 * This encodes the rule the planners work to, which is a strict two-tier gate rather than a
 * weighted score:
 *
 *   1. Anything with an outstanding order that stock cannot cover, earliest due first and
 *      overdue lines ahead of the rest.
 *   2. Only once tier one is exhausted, safety stock for the fastest-moving SKUs, highest
 *      selling first.
 *
 * A SKU with no backorder is not scheduled while any order-backed work remains, so a
 * high-velocity replenishment line can never outrank a late order. Exhausting tier one within
 * the horizon is what 'caught up with demand' means — the planner simply runs out of tier-one
 * requirements and starts drawing on tier two.
 *
 * Slower movers are made to order and never generate a replenishment line at all.
 */
final class DemandRequirementBuilder
{
    /**
     * @param  Collection<int, SkuDemandProfile>  $profiles
     * @return Collection<int, DemandRequirement> In the order the planner should satisfy them.
     */
    public function build(
        Collection $profiles,
        float $targetMonthsOnHand = DemandModel::DEFAULT_TARGET_MONTHS_ON_HAND,
        bool $includeReplenishment = true,
    ): Collection {
        $orderBacked = $this->orderBackedRequirements($profiles);

        if (! $includeReplenishment) {
            return $orderBacked;
        }

        return $orderBacked->concat($this->replenishmentRequirements($profiles, $targetMonthsOnHand))->values();
    }

    /**
     * True when no order-backed work is left, which is the condition for building safety stock.
     *
     * @param  Collection<int, SkuDemandProfile>  $profiles
     */
    public function isDemandCaughtUp(Collection $profiles): bool
    {
        return $profiles->every(fn (SkuDemandProfile $profile): bool => ! $profile->hasOrderBackedDemand());
    }

    /**
     * Total eaches still owed to customers across the plant, for the plan summary.
     *
     * @param  Collection<int, SkuDemandProfile>  $profiles
     */
    public function uncoveredOrderQty(Collection $profiles): int
    {
        return (int) $profiles->sum(fn (SkuDemandProfile $profile): int => $profile->orderBackedQty());
    }

    /**
     * @param  Collection<int, SkuDemandProfile>  $profiles
     * @return Collection<int, DemandRequirement>
     */
    private function orderBackedRequirements(Collection $profiles): Collection
    {
        return $profiles
            ->filter(fn (SkuDemandProfile $profile): bool => $profile->hasOrderBackedDemand())
            ->map(fn (SkuDemandProfile $profile): DemandRequirement => new DemandRequirement(
                depotId: $profile->depotId,
                sku: $profile->sku,
                quantity: $profile->orderBackedQty(),
                source: DemandRequirement::SOURCE_ORDER_BACKED,
                reasonCode: $profile->overdueQty > 0 ? 'order_overdue' : 'order_due',
                dueDate: $profile->earliestDueDate,
                overdueQty: $profile->overdueQty,
                abcClass: $profile->abcClass,
                velocityMonthlyEa: $profile->r3mMonthlyEa,
            ))
            // Overdue first, then earliest due date. A line with no date sorts last rather than
            // jumping the queue on a null.
            ->sortBy(fn (DemandRequirement $requirement): string => sprintf(
                '%d|%s|%012d',
                $requirement->isOverdue() ? 0 : 1,
                $requirement->dueDate?->toDateString() ?? '9999-12-31',
                PHP_INT_MAX - $requirement->quantity,
            ))
            ->values();
    }

    /**
     * @param  Collection<int, SkuDemandProfile>  $profiles
     * @return Collection<int, DemandRequirement>
     */
    private function replenishmentRequirements(Collection $profiles, float $targetMonthsOnHand): Collection
    {
        return $profiles
            ->filter(fn (SkuDemandProfile $profile): bool => $profile->isFastMover())
            ->map(fn (SkuDemandProfile $profile): DemandRequirement => new DemandRequirement(
                depotId: $profile->depotId,
                sku: $profile->sku,
                quantity: $profile->replenishmentQty($targetMonthsOnHand),
                source: DemandRequirement::SOURCE_REPLENISHMENT,
                reasonCode: 'replenish_fast_mover',
                dueDate: null,
                overdueQty: 0,
                abcClass: $profile->abcClass,
                velocityMonthlyEa: $profile->r3mMonthlyEa,
            ))
            ->filter(fn (DemandRequirement $requirement): bool => $requirement->quantity > 0)
            ->sortByDesc(fn (DemandRequirement $requirement): float => $requirement->velocityMonthlyEa)
            ->values();
    }
}
