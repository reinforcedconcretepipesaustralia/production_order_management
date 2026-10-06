<?php

namespace App\Models;

use App\Enums\DeliveryConfirmationStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryConfirmationSend extends Model
{
    protected $fillable = [
        'tracking_token',
        'entity',
        'reference_nbr',
        'reference_duplicate',
        'customer_code',
        'customer_name',
        'recipient_email',
        'sender_mailbox',
        'stage',
        'target_delivery_date',
        'line_count',
        'total_outstanding_qty',
        'status',
        'sent_at',
        'provider_message_id',
        'failure_category',
        'failure_message',
        'reply_received_at',
        'reply_provider_message_id',
        'reply_outcome',
        'reply_outcome_message',
    ];

    protected function casts(): array
    {
        return [
            'stage' => DeliveryConfirmationStage::class,
            'target_delivery_date' => 'date',
            'reference_duplicate' => 'integer',
            'line_count' => 'integer',
            'total_outstanding_qty' => 'decimal:2',
            'sent_at' => 'datetime',
            'reply_received_at' => 'datetime',
        ];
    }

    public function lineResponses(): HasMany
    {
        return $this->hasMany(DeliveryConfirmationLineResponse::class);
    }
}
