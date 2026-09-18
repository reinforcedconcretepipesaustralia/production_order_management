<?php

namespace App\Models;

use Database\Factories\InventorySohFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventorySoh extends Model
{
    /** @use HasFactory<InventorySohFactory> */
    use HasFactory;

    protected $table = 'inventory_soh';

    protected $fillable = [
        'depot_id', 'sku_master_id', 'bin_location', 'prime_quantity',
        'rework_quantity', 'quarantine_quantity', 'counted_at',
    ];

    protected function casts(): array
    {
        return ['counted_at' => 'datetime'];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(SkuMaster::class, 'sku_master_id');
    }

    public function orderAllocations(): HasMany
    {
        return $this->hasMany(OrderAllocation::class);
    }
}
