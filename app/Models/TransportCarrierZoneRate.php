<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportCarrierZoneRate extends Model
{
    protected $fillable = [
        'erp_entity_code', 'transport_carrier_id', 'zone_code', 'rate_per_tonne',
        'minimum_charge_amount', 'currency', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate_per_tonne' => 'decimal:4',
            'minimum_charge_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(TransportCarrier::class, 'transport_carrier_id');
    }
}
