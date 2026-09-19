<?php

namespace App\Models;

use Database\Factories\MouldMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MouldMovement extends Model
{
    /** @use HasFactory<MouldMovementFactory> */
    use HasFactory;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'mould_asset_id', 'from_depot_id', 'to_depot_id',
        'dispatched_on', 'expected_on', 'received_on', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_on' => 'date',
            'expected_on' => 'date',
            'received_on' => 'date',
        ];
    }

    public function mould(): BelongsTo
    {
        return $this->belongsTo(MouldAsset::class, 'mould_asset_id');
    }

    public function fromDepot(): BelongsTo
    {
        return $this->belongsTo(Depot::class, 'from_depot_id');
    }

    public function toDepot(): BelongsTo
    {
        return $this->belongsTo(Depot::class, 'to_depot_id');
    }
}
