<?php

namespace App\Models;

use Database\Factories\DowntimeEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DowntimeEvent extends Model
{
    /** @use HasFactory<DowntimeEventFactory> */
    use HasFactory;

    public const CATEGORY_MAINTENANCE = 'maintenance';

    public const CATEGORY_CHANGEOVER = 'changeover';

    public const CATEGORY_SHUTDOWN = 'shutdown';

    public const CATEGORY_BREAKDOWN = 'breakdown';

    protected $fillable = [
        'depot_id', 'machine_asset_id', 'shift_id', 'starts_at', 'ends_at',
        'category', 'description', 'is_planned', 'origin',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_planned' => 'boolean',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(MachineAsset::class, 'machine_asset_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Events that overlap the given window at all, including those that merely straddle it.
     */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    /**
     * Minutes of this event that fall inside the given window.
     */
    public function minutesWithin(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        $start = max($this->starts_at->getTimestamp(), $from->getTimestamp());
        $end = min($this->ends_at->getTimestamp(), $to->getTimestamp());

        return max(0, intdiv($end - $start, 60));
    }
}
