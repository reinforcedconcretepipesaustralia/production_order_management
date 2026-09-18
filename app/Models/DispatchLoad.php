<?php

namespace App\Models;

use Database\Factories\DispatchLoadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DispatchLoad extends Model
{
    /** @use HasFactory<DispatchLoadFactory> */
    use HasFactory;

    protected $attributes = ['current_weight_tonnes' => 0, 'status' => 'planned', 'notification_status' => 'pending'];

    protected $fillable = [
        'depot_id', 'load_reference', 'dispatch_date', 'capacity_tonnes',
        'current_weight_tonnes', 'status', 'notification_status', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'dispatch_date' => 'date',
            'capacity_tonnes' => 'decimal:3',
            'current_weight_tonnes' => 'decimal:3',
            'notified_at' => 'datetime',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DispatchLoadItem::class);
    }

    public function notificationAttempts(): HasMany
    {
        return $this->hasMany(DispatchNotificationAttempt::class);
    }
}
