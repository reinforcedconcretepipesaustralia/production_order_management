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

    protected $fillable = ['code', 'name', 'state', 'timezone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
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
