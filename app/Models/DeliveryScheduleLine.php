<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryScheduleLine extends Model
{
    protected $fillable = [
        'delivery_schedule_id', 'erp_entity_code', 'erp_line_reference', 'order_allocation_id',
        'transport_carrier_id', 'transport_carrier_vehicle_id', 'dispatch_qty', 'tonnage',
        'estimated_freight_amount', 'is_confirmed', 'is_delayed', 'delay_reason', 'notes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'tonnage' => 'decimal:4',
            'estimated_freight_amount' => 'decimal:2',
            'is_confirmed' => 'boolean',
            'is_delayed' => 'boolean',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(DeliverySchedule::class, 'delivery_schedule_id');
    }

    public function orderAllocation(): BelongsTo
    {
        return $this->belongsTo(OrderAllocation::class);
    }

    public function transportCarrier(): BelongsTo
    {
        return $this->belongsTo(TransportCarrier::class);
    }

    public function transportCarrierVehicle(): BelongsTo
    {
        return $this->belongsTo(TransportCarrierVehicle::class);
    }
}
