<?php

namespace App\Services\Planning;

use App\Models\SkuMaster;
use Carbon\CarbonImmutable;

/**
 * One thing that needs making, ranked. The planner consumes these in order.
 */
final readonly class DemandRequirement
{
    /** Covers orders prime stock cannot. Made first, always. */
    public const SOURCE_ORDER_BACKED = 'order_backed';

    /** Safety stock for a fast mover. Only made once the order book is covered. */
    public const SOURCE_REPLENISHMENT = 'replenishment';

    public function __construct(
        public int $depotId,
        public SkuMaster $sku,
        public int $quantity,
        public string $source,
        public string $reasonCode,
        public ?CarbonImmutable $dueDate = null,
        public int $overdueQty = 0,
        public ?string $abcClass = null,
        public float $velocityMonthlyEa = 0.0,
    ) {}

    public function isOrderBacked(): bool
    {
        return $this->source === self::SOURCE_ORDER_BACKED;
    }

    public function isOverdue(): bool
    {
        return $this->overdueQty > 0;
    }

    /**
     * A short explanation stored on the schedule line, so a planner reviewing the plan can see
     * why it is there without reconstructing the reasoning.
     */
    public function explain(): string
    {
        if ($this->isOrderBacked()) {
            $due = $this->dueDate?->format('j M Y') ?? 'no date';

            return $this->isOverdue()
                ? "Covers {$this->quantity} on order, {$this->overdueQty} already overdue (earliest due {$due})"
                : "Covers {$this->quantity} on order, earliest due {$due}";
        }

        return sprintf(
            'Safety stock for a fast mover selling %s/month; built only because the order book is covered',
            round($this->velocityMonthlyEa, 1),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'source' => $this->source,
            'quantity' => $this->quantity,
            'due_date' => $this->dueDate?->toDateString(),
            'overdue_qty' => $this->overdueQty,
            'abc_class' => $this->abcClass,
            'velocity_monthly_ea' => round($this->velocityMonthlyEa, 4),
            'explanation' => $this->explain(),
        ];
    }
}
