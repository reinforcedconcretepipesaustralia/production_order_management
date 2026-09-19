<?php

namespace App\Models;

use Database\Factories\DepotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Depot extends Model
{
    /** @use HasFactory<DepotFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'state', 'timezone', 'is_active',
        'erp_entity_code', 'erp_stock_categories', 'prime_stock_locations',
        'rework_stock_locations', 'is_planning_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_planning_enabled' => 'boolean',
            'erp_stock_categories' => 'array',
            'prime_stock_locations' => 'array',
            'rework_stock_locations' => 'array',
        ];
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function shiftPatterns(): HasMany
    {
        return $this->hasMany(DepotShiftPattern::class);
    }

    public function nonProductionDays(): HasMany
    {
        return $this->hasMany(NonProductionDay::class);
    }

    public function oeeSettings(): HasMany
    {
        return $this->hasMany(OeeSetting::class);
    }

    public function downtimeEvents(): HasMany
    {
        return $this->hasMany(DowntimeEvent::class);
    }

    public function machines(): HasMany
    {
        return $this->hasMany(MachineAsset::class);
    }

    public function moulds(): HasMany
    {
        return $this->hasMany(MouldAsset::class, 'current_depot_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ProductionSchedule::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(InventorySoh::class);
    }

    public function steelForecasts(): HasMany
    {
        return $this->hasMany(SteelForecast::class);
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function dispatchLoads(): HasMany
    {
        return $this->hasMany(DispatchLoad::class);
    }
}
