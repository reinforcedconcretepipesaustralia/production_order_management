<?php

namespace App\Models;

use Database\Factories\ProductionScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductionSchedule extends Model
{
    /** @use HasFactory<ProductionScheduleFactory> */
    use HasFactory;

    public const ORIGIN_SYSTEM = 'system';

    public const ORIGIN_MANUAL = 'manual';

    protected $attributes = [
        'planned_oee_percent' => 100,
        'status' => 'draft',
        'changeover_minutes' => 0,
        'shift_fraction' => 1,
        'origin' => self::ORIGIN_MANUAL,
        'is_locked' => false,
        'is_steamed' => false,
    ];

    protected $fillable = [
        'plan_run_id',
        'depot_id', 'shift_id', 'machine_asset_id', 'sku_master_id', 'mould_asset_id',
        'schedule_date', 'planned_start_at', 'planned_end_at', 'planned_quantity',
        'planned_oee_percent', 'effective_hours', 'status', 'changeover_minutes',
        'changeover_from_sku_code', 'changeover_notes', 'planner_notes', 'shift_fraction', 'origin',
        'reason_code', 'reason_context', 'is_locked', 'priority', 'is_steamed',
    ];

    protected function casts(): array
    {
        return [
            'schedule_date' => 'date',
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'planned_oee_percent' => 'decimal:2',
            'effective_hours' => 'decimal:3',
            'shift_fraction' => 'decimal:4',
            'reason_context' => 'array',
            'is_locked' => 'boolean',
            'is_steamed' => 'boolean',
        ];
    }

    /**
     * Planned tonnes for this line, the equivalent of Prod Plan column AB.
     */
    public function plannedTonnes(): float
    {
        return $this->planned_quantity * (float) $this->sku->unit_weight_tonnes;
    }

    /**
     * A line the planner may replace on the next run: system-generated and not locked by a
     * human. Manual lines and locked lines are always carried through untouched.
     */
    public function isReplaceableByPlanner(): bool
    {
        return $this->origin === self::ORIGIN_SYSTEM && ! $this->is_locked;
    }

    public function planRun(): BelongsTo
    {
        return $this->belongsTo(PlanRun::class);
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(MachineAsset::class, 'machine_asset_id');
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(SkuMaster::class, 'sku_master_id');
    }

    public function mould(): BelongsTo
    {
        return $this->belongsTo(MouldAsset::class, 'mould_asset_id');
    }

    public function actuals(): HasMany
    {
        return $this->hasMany(ProductionActual::class);
    }

    /**
     * The floor's current count for this batch. Quality may later release this same row.
     */
    public function latestActual(): HasOne
    {
        return $this->hasOne(ProductionActual::class)->latestOfMany();
    }

    public function orderAllocations(): HasMany
    {
        return $this->hasMany(OrderAllocation::class);
    }
}
