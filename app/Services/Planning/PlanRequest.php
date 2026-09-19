<?php

namespace App\Services\Planning;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What to plan, and the levers a planner can pull when they do.
 */
final readonly class PlanRequest
{
    /**
     * @param  list<int>|null  $depotIds  Null plans every planning-enabled plant.
     * @param  float  $targetMonthsOnHand  Cover held for a fast mover, when the SKU has no target
     *                                     of its own.
     * @param  float  $minimumRunFraction  The smallest slice of a shift worth starting a new
     *                                     product for, so a shift is not split into slivers.
     * @param  int  $maxRunsPerShift  How many products a machine may be set up for in one shift.
     *                                The workbooks run one or two; more than that is change-over
     *                                churn, and the plants pay for every change-over in lost time.
     * @param  bool  $includeReplenishment  Off plans order-backed work only, which is what to use
     *                                      when the order book is long and nothing else matters.
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?array $depotIds = null,
        public ?CarbonImmutable $asOf = null,
        public float $targetMonthsOnHand = DemandModel::DEFAULT_TARGET_MONTHS_ON_HAND,
        public float $minimumRunFraction = 0.25,
        public int $maxRunsPerShift = 2,
        public bool $includeReplenishment = true,
        public ?int $generatedBy = null,
    ) {
        if ($this->from->greaterThan($this->to)) {
            throw new InvalidArgumentException('The plan horizon must end on or after it starts.');
        }

        if ($this->minimumRunFraction <= 0 || $this->minimumRunFraction > 1) {
            throw new InvalidArgumentException('The minimum run must be greater than zero and no more than a whole shift.');
        }

        if ($this->targetMonthsOnHand < 0) {
            throw new InvalidArgumentException('Target months on hand cannot be negative.');
        }

        if ($this->maxRunsPerShift < 1) {
            throw new InvalidArgumentException('A shift must allow at least one run.');
        }
    }

    public function asOfDate(): CarbonImmutable
    {
        return $this->asOf ?? $this->from;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target_months_on_hand' => $this->targetMonthsOnHand,
            'minimum_run_fraction' => $this->minimumRunFraction,
            'max_runs_per_shift' => $this->maxRunsPerShift,
            'include_replenishment' => $this->includeReplenishment,
        ];
    }
}
