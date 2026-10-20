<?php

namespace App\Models;

use Database\Factories\DeliveryScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliverySchedule extends Model
{
    /** @use HasFactory<DeliveryScheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'depot_id', 'schedule_date', 'status', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'schedule_date' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryScheduleLine::class)->orderBy('sort_order')->orderBy('id');
    }
}
