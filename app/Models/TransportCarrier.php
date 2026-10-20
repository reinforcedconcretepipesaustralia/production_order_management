<?php

namespace App\Models;

use Database\Factories\TransportCarrierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportCarrier extends Model
{
    /** @use HasFactory<TransportCarrierFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'phone', 'is_active', 'is_pickup', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_pickup' => 'boolean',
        ];
    }

    public function emails(): HasMany
    {
        return $this->hasMany(TransportCarrierEmail::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(TransportCarrierVehicle::class);
    }

    public function zoneRates(): HasMany
    {
        return $this->hasMany(TransportCarrierZoneRate::class);
    }

    public function defaultVehicle(): ?TransportCarrierVehicle
    {
        return $this->vehicles()->where('is_default', true)->first()
            ?? $this->vehicles()->orderBy('id')->first();
    }
}
