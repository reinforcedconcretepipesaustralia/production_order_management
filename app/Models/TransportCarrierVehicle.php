<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportCarrierVehicle extends Model
{
    protected $fillable = [
        'transport_carrier_id', 'name', 'vehicle_kind', 'capacity_tonnes',
        'minimum_billable_tonnes', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'capacity_tonnes' => 'decimal:3',
            'minimum_billable_tonnes' => 'decimal:3',
            'is_default' => 'boolean',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(TransportCarrier::class, 'transport_carrier_id');
    }
}
