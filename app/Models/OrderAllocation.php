<?php

namespace App\Models;

use App\Exceptions\InvalidOrderAllocationException;
use Database\Factories\OrderAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderAllocation extends Model
{
    /** @use HasFactory<OrderAllocationFactory> */
    use HasFactory;

    protected $fillable = [
        'sales_order_id', 'inventory_soh_id', 'production_schedule_id', 'quantity',
        'allocated_at', 'status',
    ];

    protected function casts(): array
    {
        return ['allocated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (OrderAllocation $allocation): void {
            $sourceCount = (int) ($allocation->inventory_soh_id !== null)
                + (int) ($allocation->production_schedule_id !== null);

            if ($sourceCount !== 1) {
                throw new InvalidOrderAllocationException('An order allocation must have exactly one inventory or production source.');
            }

            if ($allocation->quantity <= 0) {
                throw new InvalidOrderAllocationException('Allocation quantity must be greater than zero.');
            }

            if ($allocation->inventory_soh_id !== null && $allocation->status !== 'allocated') {
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
}
