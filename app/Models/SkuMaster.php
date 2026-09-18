<?php

namespace App\Models;

use Database\Factories\SkuMasterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SkuMaster extends Model
{
    /** @use HasFactory<SkuMasterFactory> */
    use HasFactory;

    protected $table = 'sku_master';

    protected $fillable = [
        'sku_code', 'description', 'diameter_mm', 'length_mm', 'unit_weight_tonnes',
        'wire_code', 'wire_kg_per_pipe', 'concrete_volume_m3', 'standard_run_rate_per_hour',
        'bom_reference', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'unit_weight_tonnes' => 'decimal:4',
            'wire_kg_per_pipe' => 'decimal:3',
            'concrete_volume_m3' => 'decimal:4',
            'standard_run_rate_per_hour' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ProductionSchedule::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(InventorySoh::class);
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }
}
