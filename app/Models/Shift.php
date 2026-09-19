<?php

namespace App\Models;

use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    protected $fillable = [
        'depot_id', 'code', 'name', 'starts_at', 'duration_minutes', 'prestart_minutes',
        'cleaning_minutes', 'performance_target_percent', 'availability_target_percent', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'performance_target_percent' => 'decimal:2',
            'availability_target_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ProductionSchedule::class);
    }

    public function patterns(): HasMany
    {
        return $this->hasMany(DepotShiftPattern::class);
    }

    public function oeeSettings(): HasMany
    {
        return $this->hasMany(OeeSetting::class);
    }

    /**
     * Shift length on a given weekday (0 = Sunday), falling back to duration_minutes when
     * no pattern row exists. Returns null when the shift does not run that day.
     */
    public function durationMinutesForWeekday(int $weekday): ?int
    {
        $pattern = $this->patterns->firstWhere('weekday', $weekday);

        if ($pattern === null) {
            return $this->patterns->isEmpty() ? $this->duration_minutes : null;
        }

        return $pattern->is_active ? $pattern->duration_minutes : null;
    }
}
