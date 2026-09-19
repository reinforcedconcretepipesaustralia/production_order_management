<?php

namespace App\Models;

use Database\Factories\MachineAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MachineAsset extends Model
{
    /** @use HasFactory<MachineAssetFactory> */
    use HasFactory;

    protected $fillable = ['depot_id', 'code', 'name', 'machine_type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ProductionSchedule::class);
    }

    public function runRates(): HasMany
    {
        return $this->hasMany(MachineSkuRunRate::class);
    }

    public function oeeSettings(): HasMany
    {
        return $this->hasMany(OeeSetting::class);
    }

    public function downtimeEvents(): HasMany
    {
        return $this->hasMany(DowntimeEvent::class);
    }
}
