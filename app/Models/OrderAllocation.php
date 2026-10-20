<?php

namespace App\Models;

use App\Exceptions\InvalidOrderAllocationException;
use Database\Factories\OrderAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderAllocation extends Model
{
    /** @use HasFactory<OrderAllocationFactory> */
    use HasFactory;

    protected $fillable = [
        'sales_order_id', 'inventory_soh_id', 'production_schedule_id', 'quantity',
        'allocated_at', 'status', 'erp_entity_code', 'erp_order_reference',
        'erp_line_reference', 'stock_location', 'depot_id', 'sku_master_id',
    ];

    protected function casts(): array
    {
        return ['allocated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (OrderAllocation $allocation): void {
            $sourceCount = (int) ($allocation->inventory_soh_id !== null || filled($allocation->stock_location))
                + (int) ($allocation->production_schedule_id !== null);

            if ($sourceCount !== 1) {
                throw new InvalidOrderAllocationException('An order allocation must have exactly one inventory or production source.');
            }

            if ($allocation->quantity <= 0) {
                throw new InvalidOrderAllocationException('Allocation quantity must be greater than zero.');
            }

            if (($allocation->inventory_soh_id !== null || filled($allocation->stock_location)) && $allocation->status !== 'allocated') {
                throw new InvalidOrderAllocationException('Inventory allocations must have allocated status.');
            }

            if ($allocation->production_schedule_id !== null && $allocation->status !== 'reserved') {
                throw new InvalidOrderAllocationException('Production allocations must have reserved status.');
            }
        });
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(SkuMaster::class, 'sku_master_id');
    }

    public function productionSchedule(): BelongsTo
    {
        return $this->belongsTo(ProductionSchedule::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(InventorySoh::class, 'inventory_soh_id');
    }

    public function dispatchItems(): HasMany
    {
        return $this->hasMany(DispatchLoadItem::class);
    }

    public function resolveDepotId(): ?int
    {
        return $this->depot_id ?? $this->salesOrder?->depot_id;
    }

    public function erpOrderReferenceForDisplay(): string
    {
        return (string) ($this->salesOrder?->erp_order_reference ?? $this->erp_order_reference ?? '—');
    }

    public function skuForDisplay(): ?SkuMaster
    {
        if ($this->relationLoaded('sku') && $this->sku !== null) {
            return $this->sku;
        }

        if ($this->relationLoaded('salesOrder') && $this->salesOrder?->relationLoaded('sku')) {
            return $this->salesOrder?->sku;
        }

        return $this->sku ?? $this->salesOrder?->sku;
    }

    /**
     * @param  Builder<OrderAllocation>  $query
     * @return Builder<OrderAllocation>
     */
    public function scopeForDepot(Builder $query, int $depotId): Builder
    {
        return $query->where(function (Builder $inner) use ($depotId): void {
            $inner->where('depot_id', $depotId)
                ->orWhereHas('salesOrder', fn (Builder $orderQuery) => $orderQuery->where('depot_id', $depotId));
        });
    }
}
