<?php

namespace App\Services\Planning;

use App\Models\MaterialRequirement;
use App\Models\PlanRun;
use App\Models\ProductionSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns a production plan into what has to be bought and batched for it.
 *
 * Replaces the workbook's Prod Plan!AO:AV steel columns and its Steel Forecast tab. Weekly, because
 * that is the cadence wire is ordered on, and per wire gauge because a pipe consumes several and
 * they are ordered separately.
 *
 * Arithmetic is in grams and millilitres so the rollup cannot drift: a plan is tens of thousands of
 * pipes and floating-point kilograms accumulate error across that many additions.
 */
final class MaterialForecastService
{
    /** Wire is held per pipe in kilograms to three places, so a gram is the smallest unit needed. */
    private const GRAMS_PER_KG = 1000;

    /** Concrete volume is held to four places of a cubic metre; a tenth of a litre covers it. */
    private const UNITS_PER_M3 = 10000;

    /**
     * Recompute the whole run's requirements. Idempotent: the run's previous rows are replaced, so a
     * regenerated plan cannot leave a stale week behind to be ordered against.
     */
    public function forPlanRun(PlanRun $planRun): MaterialForecast
    {
        /** @var array<string, array{depot_id: int, week: CarbonImmutable, kind: string, code: string, unit: string, amount: int, pipes: int}> $totals */
        $totals = [];

        ProductionSchedule::query()
            ->where('plan_run_id', $planRun->id)
            ->where('planned_quantity', '>', 0)
            ->with(['sku.wireRequirements'])
            ->chunkById(500, function ($schedules) use (&$totals): void {
                foreach ($schedules as $schedule) {
                    $this->accumulate($schedule, $totals);
                }
            });

        $this->persist($planRun, $totals);

        return new MaterialForecast($planRun, array_values($totals));
    }

    /**
     * @param  array<string, array{depot_id: int, week: CarbonImmutable, kind: string, code: string, unit: string, amount: int, pipes: int}>  $totals
     */
    private function accumulate(ProductionSchedule $schedule, array &$totals): void
    {
        $sku = $schedule->sku;

        if ($sku === null) {
            return;
        }

        $week = CarbonImmutable::parse($schedule->schedule_date)->startOfWeek();
        $pipes = (int) $schedule->planned_quantity;

        foreach ($sku->wireRequirements as $requirement) {
            $this->add(
                $totals,
                $schedule->depot_id,
                $week,
                MaterialRequirement::KIND_WIRE,
                $requirement->wire_code,
                'kg',
                (int) round((float) $requirement->kg_per_pipe * self::GRAMS_PER_KG) * $pipes,
                $pipes,
            );
        }

        $volume = (float) $sku->concrete_volume_m3;

        if ($volume > 0) {
            $this->add(
                $totals,
                $schedule->depot_id,
                $week,
                MaterialRequirement::KIND_CONCRETE,
                // A pipe with no mix code recorded still has a volume worth batching for.
                $sku->concrete_mix_code ?: 'MIX (unspecified)',
                'm3',
                (int) round($volume * self::UNITS_PER_M3) * $pipes,
                $pipes,
            );
        }
    }

    /**
     * @param  array<string, array{depot_id: int, week: CarbonImmutable, kind: string, code: string, unit: string, amount: int, pipes: int}>  $totals
     */
    private function add(
        array &$totals,
        int $depotId,
        CarbonImmutable $week,
        string $kind,
        string $code,
        string $unit,
        int $amount,
        int $pipes,
    ): void {
        $key = $depotId.'|'.$week->toDateString().'|'.$code;

        $totals[$key] ??= [
            'depot_id' => $depotId,
            'week' => $week,
            'kind' => $kind,
            'code' => $code,
            'unit' => $unit,
            'amount' => 0,
            'pipes' => 0,
        ];

        $totals[$key]['amount'] += $amount;
        $totals[$key]['pipes'] += $pipes;
    }

    /**
     * @param  array<string, array{depot_id: int, week: CarbonImmutable, kind: string, code: string, unit: string, amount: int, pipes: int}>  $totals
     */
    private function persist(PlanRun $planRun, array $totals): void
    {
        $rows = [];
        $now = now();

        foreach ($totals as $total) {
            $divisor = $total['unit'] === 'kg' ? self::GRAMS_PER_KG : self::UNITS_PER_M3;

            $rows[] = [
                'plan_run_id' => $planRun->id,
                'depot_id' => $total['depot_id'],
                'week_starting' => $total['week']->toDateString(),
                'kind' => $total['kind'],
                'material_code' => $total['code'],
                'unit' => $total['unit'],
                'quantity' => round($total['amount'] / $divisor, 4),
                'planned_pipes' => $total['pipes'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($planRun, $rows): void {
            MaterialRequirement::query()->where('plan_run_id', $planRun->id)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                MaterialRequirement::query()->insert($chunk);
            }
        });
    }
}
