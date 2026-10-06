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
        'sku_code', 'erp_entity_code', 'description', 'diameter_mm', 'length_mm', 'unit_weight_tonnes',
        'wire_code', 'wire_kg_per_pipe', 'concrete_volume_m3', 'concrete_mix_code',
        'standard_run_rate_per_hour',
        'bom_reference', 'mould_type', 'reo_cage', 'moulds_count', 'pallets_count',
        'is_active', 'machine_preference', 'abc_class',
        'minimum_run_quantity', 'target_months_on_hand',
    ];

    protected function casts(): array
    {
        return [
            'unit_weight_tonnes' => 'decimal:4',
            'wire_kg_per_pipe' => 'decimal:3',
            'concrete_volume_m3' => 'decimal:4',
            'standard_run_rate_per_hour' => 'decimal:3',
            'target_months_on_hand' => 'decimal:2',
            'moulds_count' => 'integer',
            'pallets_count' => 'integer',
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

    public function wireRequirements(): HasMany
    {
        return $this->hasMany(SkuWireRequirement::class);
    }

    public function machineRunRates(): HasMany
    {
        return $this->hasMany(MachineSkuRunRate::class);
    }

    /**
     * 'A' SKUs make up the top 80% of R3M eaches and are made to stock; everything else
     * is made to order. Set by the demand model, not by hand.
     */
    public function isMakeToStock(): bool
    {
        return $this->abc_class === 'A';
    }
}
