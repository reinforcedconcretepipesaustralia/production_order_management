<?php

namespace App\Models;

use Database\Factories\ProductionScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionSchedule extends Model
{
    /** @use HasFactory<ProductionScheduleFactory> */
    use HasFactory;

    protected $attributes = ['planned_oee_percent' => 100, 'status' => 'draft', 'changeover_minutes' => 0];

    protected $fillable = [
        'depot_id', 'shift_id', 'machine_asset_id', 'sku_master_id', 'mould_asset_id',
        'schedule_date', 'planned_start_at', 'planned_end_at', 'planned_quantity',
        'planned_oee_percent', 'effective_hours', 'status', 'changeover_minutes',
        'changeover_from_sku_code', 'changeover_notes',
    ];

    protected function casts(): array
    {
        return [
            'schedule_date' => 'date',
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'planned_oee_percent' => 'decimal:2',
            'effective_hours' => 'decimal:3',
        ];
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

    public function orderAllocations(): HasMany
    {
        return $this->hasMany(OrderAllocation::class);
    }
}
