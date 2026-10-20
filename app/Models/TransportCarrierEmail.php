<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportCarrierEmail extends Model
{
    protected $fillable = [
        'transport_carrier_id', 'email', 'label', 'is_primary',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(TransportCarrier::class, 'transport_carrier_id');
    }
}
