<?php

namespace App\Models;

use Database\Factories\DemandForecastFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandForecast extends Model
{
    /** @use HasFactory<DemandForecastFactory> */
    use HasFactory;

    /** Only 'A' SKUs are eligible for safety stock. */
    public const CLASS_FAST_MOVER = 'A';

    protected $fillable = [
        'depot_id', 'sku_master_id', 'as_of_date',
        'r3m_monthly_ea', 'r6m_monthly_ea', 'r12m_monthly_ea',
        'outstanding_qty', 'overdue_qty', 'current_month_qty', 'outstanding_by_month',
        'earliest_due_date', 'prime_soh_qty', 'rework_soh_qty', 'net_position_qty',
        'months_on_hand', 'equivalent_months', 'has_demand_signal',
        'abc_class', 'abc_cumulative_share', 'order_backed_qty', 'replenishment_qty',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'earliest_due_date' => 'date',
            'r3m_monthly_ea' => 'decimal:4',
            'r6m_monthly_ea' => 'decimal:4',
            'r12m_monthly_ea' => 'decimal:4',
            'outstanding_by_month' => 'array',
            'months_on_hand' => 'decimal:4',
            'equivalent_months' => 'decimal:4',
            'abc_cumulative_share' => 'decimal:6',
            'has_demand_signal' => 'boolean',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(SkuMaster::class, 'sku_master_id');
    }

    public function isFastMover(): bool
    {
        return $this->abc_class === self::CLASS_FAST_MOVER;
    }
}
