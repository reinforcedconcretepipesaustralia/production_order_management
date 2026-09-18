<?php

namespace App\Models;

use App\Exceptions\IneligibleLoadQuantityException;
use Database\Factories\DispatchLoadItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchLoadItem extends Model
{
    /** @use HasFactory<DispatchLoadItemFactory> */
    use HasFactory;

    protected $fillable = ['dispatch_load_id', 'order_allocation_id', 'quantity', 'weight_tonnes'];

    protected function casts(): array
    {
        return ['weight_tonnes' => 'decimal:3'];
    }

    protected static function booted(): void
    {
        static::saving(function (DispatchLoadItem $item): void {
            if ($item->quantity <= 0 || $item->weight_tonnes < 0) {
                throw new \InvalidArgumentException('Dispatch quantity must be positive and weight cannot be negative.');
            }

            $allocation = $item->orderAllocation()->first();
            if ($allocation === null) {
                throw new IneligibleLoadQuantityException('Dispatch item must reference an order allocation.');
            }

            $quantityOnOtherLoads = static::query()
                ->where('order_allocation_id', $allocation->id)
                ->when($item->exists, fn ($query) => $query->whereKeyNot($item->getKey()))
                ->sum('quantity');

            if ($quantityOnOtherLoads + $item->quantity > $allocation->quantity) {
                throw new IneligibleLoadQuantityException('Dispatch quantity cannot exceed its source allocation.');
            }
        });
    }

    public function dispatchLoad(): BelongsTo
    {
        return $this->belongsTo(DispatchLoad::class);
    }

    public function orderAllocation(): BelongsTo
    {
        return $this->belongsTo(OrderAllocation::class);
    }
}
