<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryConfirmationLineResponse extends Model
{
    protected $fillable = [
        'delivery_confirmation_send_id',
        'line_unique_id',
        'stock_code',
        'description',
        'outstanding_qty',
        'current_delivery_date',
        'requested_delivery_date',
        'action',
        'outcome',
        'outcome_message',
        'tencia_updated_at',
        'needs_rep_approval',
        'approved_at',
        'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'line_unique_id' => 'integer',
            'outstanding_qty' => 'decimal:2',
            'current_delivery_date' => 'date',
            'requested_delivery_date' => 'date',
            'tencia_updated_at' => 'datetime',
            'needs_rep_approval' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function send(): BelongsTo
    {
        return $this->belongsTo(DeliveryConfirmationSend::class, 'delivery_confirmation_send_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
