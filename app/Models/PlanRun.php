<?php

namespace App\Models;

use Database\Factories\PlanRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanRun extends Model
{
    /** @use HasFactory<PlanRunFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DISCARDED = 'discarded';

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected $fillable = [
        'horizon_from', 'horizon_to', 'as_of_date', 'depot_ids', 'status',
        'parameters', 'metrics', 'narrative', 'generated_by', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'horizon_from' => 'date',
            'horizon_to' => 'date',
            'as_of_date' => 'date',
            'depot_ids' => 'array',
            'parameters' => 'array',
            'metrics' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ProductionSchedule::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
