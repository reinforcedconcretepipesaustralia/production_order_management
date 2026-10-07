<?php

namespace App\Models;

use Database\Factories\SchedulingRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchedulingRule extends Model
{
    /** @use HasFactory<SchedulingRuleFactory> */
    use HasFactory;

    public const CODE_MAX_RUNS_PER_SHIFT = 'max_runs_per_shift';

    public const CODE_MINIMUM_RUN_FRACTION = 'minimum_run_fraction';

    public const CODE_TARGET_MONTHS_ON_HAND = 'target_months_on_hand';

    public const CODE_INCLUDE_REPLENISHMENT = 'include_replenishment';

    protected $fillable = [
        'code', 'name', 'category', 'description', 'value', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<SchedulingRule>  $query
     * @return Builder<SchedulingRule>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }
}
