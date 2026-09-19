<?php

namespace App\Models;

use Database\Factories\DepotShiftPatternFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepotShiftPattern extends Model
{
    /** @use HasFactory<DepotShiftPatternFactory> */
    use HasFactory;

    protected $fillable = ['depot_id', 'shift_id', 'weekday', 'duration_minutes', 'is_active'];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * depot_id is denormalised from the shift so the capacity calendar can filter patterns
     * by plant without a join. It is always derived, never entered — a pattern created from
     * the shift form's repeater has no depot of its own to set.
     */
    protected static function booted(): void
    {
        static::saving(function (self $pattern): void {
            $depotId = Shift::query()->whereKey($pattern->shift_id)->value('depot_id');

            if ($depotId !== null) {
                $pattern->depot_id = $depotId;
            }
        });
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
