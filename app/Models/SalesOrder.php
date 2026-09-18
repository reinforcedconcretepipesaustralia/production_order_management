<?php

namespace App\Models;

use Database\Factories\SalesOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SalesOrder extends Model
{
    /** @use HasFactory<SalesOrderFactory> */
    use HasFactory;

    protected $attributes = ['allocated_quantity' => 0, 'status' => 'open'];

    protected $fillable = [
        'depot_id', 'sku_master_id', 'erp_order_reference', 'erp_line_reference',
        'customer_code', 'customer_name', 'delivery_address', 'ordered_quantity',
        'allocated_quantity', 'requested_delivery_date', 'ordered_at', 'status',
    ];

    protected function casts(): array
    {
        return ['requested_delivery_date' => 'date', 'ordered_at' => 'datetime'];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(SkuMaster::class, 'sku_master_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(OrderAllocation::class);
    }

    public function dispatchItems(): HasManyThrough
    {
        return $this->hasManyThrough(
            DispatchLoadItem::class,
            OrderAllocation::class,
            'sales_order_id',
            'order_allocation_id',
        );
    }
}
