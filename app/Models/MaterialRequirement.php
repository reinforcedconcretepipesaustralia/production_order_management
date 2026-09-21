<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a plan run will consume, per plant per week per material. See the migration for why this is
 * separate from steel_forecasts.
 */
class MaterialRequirement extends Model
{
    use HasFactory;

    public const KIND_WIRE = 'wire';

    public const KIND_CONCRETE = 'concrete';

    protected $fillable = [
        'plan_run_id', 'depot_id', 'week_starting', 'kind',
        'material_code', 'unit', 'quantity', 'planned_pipes',
    ];

    protected function casts(): array
    {
        return [
            'week_starting' => 'date',
            'quantity' => 'decimal:4',
            'planned_pipes' => 'integer',
        ];
    }

    public function planRun(): BelongsTo
    {
        return $this->belongsTo(PlanRun::class);
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function scopeWire(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_WIRE);
    }

    public function scopeConcrete(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_CONCRETE);
    }
}
