<?php

namespace App\Models;

use Database\Factories\MachineSkuRunRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineSkuRunRate extends Model
{
    /** @use HasFactory<MachineSkuRunRateFactory> */
    use HasFactory;

    protected $fillable = [
        'machine_asset_id', 'sku_master_id', 'rated_per_hour',
        'adjustment_factor', 'changeover_minutes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rated_per_hour' => 'decimal:3',
            'adjustment_factor' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(MachineAsset::class, 'machine_asset_id');
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(SkuMaster::class, 'sku_master_id');
    }

    /**
     * The rate the planner uses, mirroring BOM column I: ROUNDUP(rated x adjustment, 0).
     * An unset adjustment means the rated speed is used as-is.
     */
    public function effectiveRatePerHour(): float
    {
        $rated = (float) $this->rated_per_hour;

        if ($this->adjustment_factor === null) {
            return $rated;
        }

        return (float) ceil($rated * (float) $this->adjustment_factor);
    }
}
