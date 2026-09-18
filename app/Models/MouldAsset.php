<?php

namespace App\Models;

use Database\Factories\MouldAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MouldAsset extends Model
{
    /** @use HasFactory<MouldAssetFactory> */
    use HasFactory;

    protected $fillable = [
        'current_depot_id', 'code', 'diameter_mm', 'mould_type', 'is_shared',
        'machine_head_constraint', 'shared_component_constraint', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_shared' => 'boolean', 'is_active' => 'boolean'];
    }

    public function currentDepot(): BelongsTo
    {
        return $this->belongsTo(Depot::class, 'current_depot_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ProductionSchedule::class);
    }
}
