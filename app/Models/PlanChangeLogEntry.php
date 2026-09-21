<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planner change to a generated plan line. See the migration for why it is kept.
 */
class PlanChangeLogEntry extends Model
{
    use HasFactory;

    protected $table = 'plan_change_log';

    protected $fillable = [
        'plan_run_id', 'production_schedule_id', 'user_id',
        'line_description', 'field', 'from_value', 'to_value', 'reason',
    ];

    public function planRun(): BelongsTo
    {
        return $this->belongsTo(PlanRun::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ProductionSchedule::class, 'production_schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Reads as a sentence in the log, e.g. "Quantity 340 → 300".
     */
    public function summary(): string
    {
        return sprintf(
            '%s %s → %s',
            ucfirst(str_replace('_', ' ', $this->field)),
            $this->from_value ?? 'not set',
            $this->to_value ?? 'not set',
        );
    }
}
