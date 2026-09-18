<?php

namespace App\Models;

use Database\Factories\SteelForecastFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SteelForecast extends Model
{
    /** @use HasFactory<SteelForecastFactory> */
    use HasFactory;

    protected $fillable = [
        'depot_id', 'forecast_date', 'wire_code', 'on_hand_coils',
        'average_coil_weight_tonnes', 'on_order_coils', 'transfer_in_coils',
        'transfer_out_coils', 'safety_threshold_coils',
    ];

    protected function casts(): array
    {
        return ['forecast_date' => 'date', 'average_coil_weight_tonnes' => 'decimal:4'];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }
}
