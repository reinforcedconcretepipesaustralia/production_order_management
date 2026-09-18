<?php

namespace App\Models;

use Database\Factories\DispatchNotificationAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchNotificationAttempt extends Model
{
    /** @use HasFactory<DispatchNotificationAttemptFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'queued',
        'attempt_count' => 0,
    ];

    protected $fillable = [
        'dispatch_load_id',
        'provider',
        'event_type',
        'recipients',
        'status',
        'attempt_count',
        'attempted_at',
        'sent_at',
        'provider_request_id',
        'idempotency_key',
        'actor_user_id',
        'failure_category',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'encrypted:array',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function dispatchLoad(): BelongsTo
    {
        return $this->belongsTo(DispatchLoad::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
