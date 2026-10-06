<?php

namespace App\Services\Planning;

use App\Models\PlanChangeLogEntry;
use App\Models\PlanRun;
use App\Models\ProductionSchedule;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What a planner is allowed to change about a generated plan, and the record of them changing it.
 *
 * The model's promise is that a human reviews and adjusts rather than authors. That only holds if an
 * adjustment survives the next regeneration, which is what locking is for, and if what was adjusted
 * stays visible, which is what the log is for.
 */
final class PlanReviewService
{
    /**
     * Fields a planner may change on a line, and how each reads in the log.
     *
     * Deliberately short. Anything not here is the engine's to decide, and a planner who needs to
     * change it is telling us the engine is missing a rule.
     */
    private const EDITABLE = [
        'planned_quantity' => 'quantity',
        'schedule_date' => 'date',
        'shift_id' => 'shift',
        'shift_fraction' => 'share of shift',
        'priority' => 'priority',
        'is_steamed' => 'steamed',
        'planner_notes' => 'notes',
    ];

    /**
     * Apply a planner's edits to a line, recording each one.
     *
     * @param  array<string, mixed>  $changes
     * @return int How many fields actually changed.
     */
    public function updateLine(
        ProductionSchedule $line,
        array $changes,
        ?User $user = null,
        ?string $reason = null,
    ): int {
        $this->guardEditable($line);

        $applied = 0;

        DB::transaction(function () use ($line, $changes, $user, $reason, &$applied): void {
            foreach ($changes as $field => $value) {
                if (! array_key_exists($field, self::EDITABLE)) {
                    throw new RuntimeException("[{$field}] is not a field a planner may change.");
                }

                if ($field === 'schedule_date') {
                    $value = $value === null || $value === ''
                        ? null
                        : CarbonImmutable::parse((string) $value)->toDateString();
                    $before = $line->schedule_date?->toDateString();
                } elseif ($field === 'shift_id') {
                    $value = $value === null || $value === '' ? null : (int) $value;
                    $before = $line->shift_id;
                } else {
                    $before = $line->getAttribute($field);
                }

                if ($this->same($before, $value)) {
                    continue;
                }

                if ($field === 'schedule_date') {
                    if ($value === null) {
                        throw new RuntimeException('A plan line needs a date.');
                    }

                    $this->moveLineToDate($line, $value);
                } elseif ($field === 'shift_id') {
                    $this->moveLineToShift($line, $value);
                } else {
                    $line->setAttribute($field, $value);
                }

                // A line a planner has touched is theirs: regeneration must not take it back.
                $line->origin = ProductionSchedule::ORIGIN_MANUAL;

                $this->log($line, self::EDITABLE[$field], $before, $value, $user, $reason);
                $applied++;
            }

            if ($applied > 0) {
                $line->save();
            }
        });

        return $applied;
    }

    /**
     * Hold a line against regeneration, or release it.
     */
    public function setLocked(
        ProductionSchedule $line,
        bool $locked,
        ?User $user = null,
        ?string $reason = null,
    ): void {
        $this->guardReviewable($line);

        if ($line->is_locked === $locked) {
            return;
        }

        DB::transaction(function () use ($line, $locked, $user, $reason): void {
            $this->log($line, 'locked', $line->is_locked ? 'yes' : 'no', $locked ? 'yes' : 'no', $user, $reason);

            $line->is_locked = $locked;
            $line->save();
        });
    }

    /**
     * Sign the plan off. An approved run is what the factory works to, so it stops being
     * regenerable and every line in it is held.
     */
    public function approve(PlanRun $run, ?User $user = null): void
    {
        if (! $run->isDraft()) {
            throw new RuntimeException('Only a draft plan can be approved.');
        }

        DB::transaction(function () use ($run, $user): void {
            // The run's own lines and the ones carried over from an earlier run: both are work the
            // floor will do, so approval commits both.
            $this->reviewableLines($run)
                ->update(['is_locked' => true, 'status' => 'scheduled']);

            $run->update([
                'status' => PlanRun::STATUS_APPROVED,
                'approved_by' => $user?->id,
                'approved_at' => now(),
            ]);
        });
    }

    /**
     * Set a plan aside. Its lines go with it, so a discarded plan cannot be worked to by accident.
     */
    public function discard(PlanRun $run): void
    {
        if ($run->isApproved()) {
            throw new RuntimeException('An approved plan cannot be discarded. Generate a new one instead.');
        }

        DB::transaction(function () use ($run): void {
            ProductionSchedule::query()
                ->where('plan_run_id', $run->id)
                ->update(['status' => 'cancelled']);

            $run->update(['status' => PlanRun::STATUS_DISCARDED]);
        });
    }

    /**
     * Hold and schedule the lines in a week or month. The run stays a draft until every remaining
     * line in it is held, so a planner can sign off one week without locking the rest of the horizon.
     */
    public function approvePeriod(
        PlanRun $run,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?User $user = null,
    ): int {
        if (! $run->isDraft()) {
            throw new RuntimeException('Only a draft plan can be approved.');
        }

        if ($to->isBefore($from)) {
            throw new RuntimeException('The approval window must end on or after it starts.');
        }

        $held = 0;

        DB::transaction(function () use ($run, $from, $to, $user, &$held): void {
            $held = $this->reviewableLines($run)
                ->whereBetween('schedule_date', [$from->startOfDay(), $to->endOfDay()])
                ->update(['is_locked' => true, 'status' => 'scheduled']);

            $stillOpen = $this->reviewableLines($run)
                ->where('is_locked', false)
                ->exists();

            if (! $stillOpen) {
                $run->update([
                    'status' => PlanRun::STATUS_APPROVED,
                    'approved_by' => $user?->id,
                    'approved_at' => now(),
                ]);
            }
        });

        return $held;
    }

    /**
     * Keep the line on the same clock when the planner moves the day, so a 6am start stays 6am.
     */
    private function moveLineToDate(ProductionSchedule $line, string $date): void
    {
        $newDate = CarbonImmutable::parse($date)->startOfDay();
        $oldDate = $line->schedule_date === null
            ? null
            : CarbonImmutable::parse($line->schedule_date->toDateString())->startOfDay();

        if ($oldDate !== null && $line->planned_start_at !== null) {
            $days = (int) $oldDate->diffInDays($newDate, false);
            $line->planned_start_at = $line->planned_start_at->copy()->addDays($days);

            if ($line->planned_end_at !== null) {
                $line->planned_end_at = $line->planned_end_at->copy()->addDays($days);
            }
        }

        $line->schedule_date = $newDate->toDateString();
    }

    private function moveLineToShift(ProductionSchedule $line, ?int $shiftId): void
    {
        if ($shiftId === null) {
            throw new RuntimeException('A plan line needs a shift.');
        }

        $shift = Shift::query()->find($shiftId);

        if ($shift === null || $shift->depot_id !== $line->depot_id) {
            throw new RuntimeException('That shift is not at the same plant as this line.');
        }

        $duration = $line->planned_start_at !== null && $line->planned_end_at !== null
            ? max(15, $line->planned_start_at->diffInMinutes($line->planned_end_at))
            : max(15, (int) $shift->duration_minutes);

        $date = $line->schedule_date === null
            ? now()->toImmutable()->startOfDay()
            : CarbonImmutable::parse($line->schedule_date->toDateString())->startOfDay();

        $start = $this->shiftStartOn($date, $shift);

        $line->shift_id = $shift->id;
        $line->planned_start_at = $start;
        $line->planned_end_at = $start->addMinutes($duration);
    }

    private function shiftStartOn(CarbonImmutable $date, Shift $shift): CarbonImmutable
    {
        $parts = array_map('intval', explode(':', (string) $shift->starts_at));

        return $date->setTime($parts[0] ?? 0, $parts[1] ?? 0);
    }

    /**
     * @return Builder<ProductionSchedule>
     */
    private function reviewableLines(PlanRun $run): Builder
    {
        return ProductionSchedule::query()
            ->where(fn (Builder $query) => $query
                ->where('plan_run_id', $run->id)
                ->orWhere(fn (Builder $carried) => $carried
                    ->whereIn('depot_id', $run->depot_ids ?? [])
                    ->whereBetween('schedule_date', [
                        $run->horizon_from->startOfDay(),
                        $run->horizon_to->endOfDay(),
                    ])
                    ->where('origin', ProductionSchedule::ORIGIN_MANUAL)
                    ->whereNot('status', 'cancelled')));
    }

    private function guardEditable(ProductionSchedule $line): void
    {
        $this->guardReviewable($line);

        if ($line->is_locked) {
            throw new RuntimeException('This line is held. Release it before changing it.');
        }
    }

    private function guardReviewable(ProductionSchedule $line): void
    {
        $run = $line->planRun;

        if ($run !== null && ! $run->isDraft()) {
            throw new RuntimeException(
                "This line belongs to a plan that is already {$run->status}. Generate a new plan to change it.",
            );
        }
    }

    private function log(
        ProductionSchedule $line,
        string $field,
        mixed $from,
        mixed $to,
        ?User $user,
        ?string $reason,
    ): void {
        PlanChangeLogEntry::query()->create([
            'plan_run_id' => $line->plan_run_id,
            'production_schedule_id' => $line->id,
            'user_id' => $user?->id,
            // Described as well as referenced, so the entry still reads once the plan is regenerated.
            'line_description' => $this->describe($line),
            'field' => $field,
            'from_value' => $this->asText($from),
            'to_value' => $this->asText($to),
            'reason' => $reason,
        ]);
    }

    private function describe(ProductionSchedule $line): string
    {
        return sprintf(
            '%s %s on %s',
            $line->depot?->code ?? 'Plant',
            $line->sku?->sku_code ?? 'product',
            $line->schedule_date?->format('j M Y') ?? 'an unknown day',
        );
    }

    private function asText(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }

    private function same(mixed $before, mixed $after): bool
    {
        if (is_bool($before) || is_bool($after)) {
            return (bool) $before === (bool) $after;
        }

        if (is_numeric($before) && is_numeric($after)) {
            return abs((float) $before - (float) $after) < 0.00005;
        }

        return (string) $before === (string) $after;
    }
}
