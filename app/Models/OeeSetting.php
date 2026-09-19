<?php

namespace App\Models;

use Database\Factories\OeeSettingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OeeSetting extends Model
{
    /** @use HasFactory<OeeSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'depot_id', 'shift_id', 'machine_asset_id', 'effective_from',
        'performance_percent', 'availability_percent', 'quality_percent',
        'prestart_minutes', 'paid_break_minutes', 'unpaid_break_minutes',
        'in_shift_cleaning_minutes', 'end_of_shift_cleaning_minutes', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'performance_percent' => 'decimal:2',
            'availability_percent' => 'decimal:2',
            'quality_percent' => 'decimal:2',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(MachineAsset::class, 'machine_asset_id');
    }

    /**
     * Total minutes deducted from a shift before production time is counted, matching the
     * 'Effective hours' line on each workbook's OEE Settings tab.
     */
    public function nonProductiveMinutes(): int
    {
        return $this->prestart_minutes
            + $this->paid_break_minutes
            + $this->unpaid_break_minutes
            + $this->in_shift_cleaning_minutes
            + $this->end_of_shift_cleaning_minutes;
    }

    /**
     * Most specific setting in force on a date: machine, then shift, then depot.
     */
    public function scopeMostSpecificFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN machine_asset_id IS NOT NULL THEN 0 WHEN shift_id IS NOT NULL THEN 1 ELSE 2 END')
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
    }
}
