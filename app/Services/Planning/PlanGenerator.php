<?php

namespace App\Services\Planning;

use App\Models\Depot;
use App\Models\DowntimeEvent;
use App\Models\PlanRun;
use App\Models\ProductionSchedule;
use App\Services\Scheduling\CapacityResult;
use App\Services\Scheduling\ShiftCapacityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns demand and capacity into a production plan.
 *
 * Deterministic and explainable by design. Every line records why it exists, so a planner
 * reviewing the plan can see the reasoning rather than having to reconstruct it, and the same
 * inputs always produce the same plan.
 *
 * The walk is simple: take the shift slots in date order, and fill each one with the most urgent
 * work the machine is actually qualified to run. Because a requirement is rarely exhausted in a
 * single shift, a machine naturally stays on the same product for as long as the work lasts,
 * which is what keeps change-overs down without needing to optimise for them.
 *
 * What a planner has already decided is never overwritten: lines they entered by hand, and lines
 * they locked, are kept and counted against demand before anything new is scheduled.
 */
final class PlanGenerator
{
    public function __construct(
        private readonly CapacityCalendarBuilder $calendar,
        private readonly DemandModel $demand,
        private readonly DemandRequirementBuilder $requirements,
        private readonly ShiftCapacityCalculator $capacity,
        private readonly OeeSettingResolver $oeeSettings,
        private readonly RunRateResolver $runRates,
        private readonly MaterialForecastService $materials,
    ) {}

    public function generate(PlanRequest $request): PlanRun
    {
        return DB::transaction(function () use ($request): PlanRun {
            $depots = $this->depotsFor($request);

            if ($depots->isEmpty()) {
                throw new \RuntimeException('No planning-enabled plants matched the request.');
            }

            $run = PlanRun::query()->create([
                'horizon_from' => $request->from,
                'horizon_to' => $request->to,
                'as_of_date' => $request->asOfDate(),
                'depot_ids' => $depots->pluck('id')->all(),
                'status' => PlanRun::STATUS_DRAFT,
                'parameters' => $request->toArray(),
                'generated_by' => $request->generatedBy,
            ]);

            $this->clearReplaceableLines($depots, $request);

            $outstanding = $this->outstandingByDepot($depots, $request);
            $this->consumeRetainedLines($depots, $request, $outstanding);

            $slots = $this->calendar->build($depots, $request->from, $request->to);
            $retained = $this->retainedFractionsBySlot($depots, $request);
            $lastSkuByMachine = [];
            $lines = 0;

            foreach ($slots as $slot) {
                $lines += $this->fillSlot($run, $slot, $request, $outstanding, $retained, $lastSkuByMachine);
            }

            // Materials are computed with the plan, not on request: a plan whose steel requirement
            // has to be asked for separately is a plan somebody orders against without it.
            $materials = $this->materials->forPlanRun($run);

            $run->update([
                'metrics' => $this->metricsFor($run, $depots, $slots, $outstanding, $lines)
                    + $this->materialMetrics($materials),
            ]);

            return $run->fresh(['schedules']);
        }, 3);
    }

    /**
     * Fills one machine's shift with the most urgent work it can run, and returns how many lines
     * were written.
     *
     * @param  array<int, Collection<int, PlannedRequirement>>  $outstanding
     * @param  array<string, string>  $retained  Slot key to the share already committed.
     * @param  array<int, string|null>  $lastSkuByMachine
     */
    private function fillSlot(
        PlanRun $run,
        CapacitySlot $slot,
        PlanRequest $request,
        array &$outstanding,
        array $retained,
        array &$lastSkuByMachine,
    ): int {
        if (! $slot->hasCapacity()) {
            return 0;
        }

        // A locked or hand-entered line already holds part of this shift.
        $alreadyUsed = $retained[$slot->key()] ?? '0.0000';
        $slot->claim($alreadyUsed);

        $minimumRun = number_format($request->minimumRunFraction, 4, '.', '');
        $queue = $outstanding[$slot->depot->id] ?? collect();
        $lines = 0;

        // A machine can only be set up for so many products in one shift. Without this the
        // planner fills the tail of a shift with a run of four pipes and then another of six,
        // paying a change-over for each — which is not how any of the plants work.
        while ($slot->hasCapacity() && $lines < $request->maxRunsPerShift) {
            $candidate = $this->pick($queue, $slot, $lastSkuByMachine[$slot->machine->id] ?? null);

            if ($candidate === null) {
                break;
            }

            $changeoverMinutes = $this->changeoverMinutesFor($slot, $candidate, $lastSkuByMachine);
            $fullShift = $this->capacityFor($slot, $candidate, $changeoverMinutes, '1.0000');

            if ($fullShift === null || $fullShift->plannedPipes <= 0) {
                // The machine cannot make anything useful of this product today; drop it from
                // consideration for this slot rather than looping forever.
                $queue = $queue->reject(fn (PlannedRequirement $item): bool => $item === $candidate);

                continue;
            }

            $needed = $this->fractionNeeded($candidate->remaining, $fullShift->plannedPipes);
            $fraction = bccomp($needed, $slot->remainingFraction(), 4) > 0 ? $slot->remainingFraction() : $needed;
            $result = $this->capacityFor($slot, $candidate, $changeoverMinutes, $fraction);

            if ($result === null || $result->plannedPipes <= 0) {
                break;
            }

            $quantity = min($result->plannedPipes, max(1, $candidate->remaining));
            // Both ends are measured from the start of the shift, so one run finishes exactly
            // where the next begins. Deriving the end from the start plus a rounded duration
            // leaves a minute of overlap or gap between consecutive runs.
            [$start, $end] = $this->windowFor($slot, $alreadyUsed, $fraction);

            ProductionSchedule::query()->create([
                'plan_run_id' => $run->id,
                'depot_id' => $slot->depot->id,
                'shift_id' => $slot->shift->id,
                'machine_asset_id' => $slot->machine->id,
                'sku_master_id' => $candidate->sku()->id,
                'mould_asset_id' => null,
                'schedule_date' => $slot->date->toDateString(),
                'planned_start_at' => $start,
                'planned_end_at' => $end,
                'planned_quantity' => $quantity,
                'shift_fraction' => $fraction,
                'planned_oee_percent' => $this->oeePercentFor($slot),
                'effective_hours' => $result->effectiveHours,
                'status' => 'draft',
                'origin' => ProductionSchedule::ORIGIN_SYSTEM,
                'reason_code' => $candidate->demand->reasonCode,
                'reason_context' => $candidate->demand->context() + [
                    'planned_quantity' => $quantity,
                    'still_outstanding' => max(0, $candidate->remaining - $quantity),
                ],
                'changeover_minutes' => $changeoverMinutes,
                'changeover_from_sku_code' => $changeoverMinutes > 0
                    ? ($lastSkuByMachine[$slot->machine->id] ?? null)
                    : null,
            ]);

            if ($changeoverMinutes > 0) {
                $this->recordChangeover($slot, $start, $changeoverMinutes, $candidate);
            }

            $candidate->take($quantity);
            $lastSkuByMachine[$slot->machine->id] = $candidate->sku()->sku_code;
            $alreadyUsed = bcadd($alreadyUsed, $slot->claim($fraction), 4);
            $lines++;

            // Starting a third product on a sliver of a shift helps nobody.
            if (bccomp($slot->remainingFraction(), $minimumRun, 4) < 0) {
                break;
            }
        }

        $outstanding[$slot->depot->id] = $queue;

        return $lines;
    }

    /**
     * The most urgent outstanding work this machine is qualified to run.
     *
     * Where several requirements are equally urgent — same source, same due date — whatever the
     * machine is already set up for wins, which avoids a change-over without ever letting a less
     * urgent product jump ahead of a more urgent one.
     *
     * @param  Collection<int, PlannedRequirement>  $queue
     */
    private function pick(Collection $queue, CapacitySlot $slot, ?string $currentSkuCode): ?PlannedRequirement
    {
        $eligible = $queue
            ->filter(fn (PlannedRequirement $item): bool => $item->isOutstanding())
            ->filter(fn (PlannedRequirement $item): bool => $this->runRates->isQualified($slot->machine, $item->sku()))
            ->sortBy(fn (PlannedRequirement $item): int => $item->rank)
            ->values();

        $top = $eligible->first();

        if ($top === null || $currentSkuCode === null) {
            return $top;
        }

        return $eligible
            ->filter(fn (PlannedRequirement $item): bool => $item->urgencyKey() === $top->urgencyKey())
            ->first(fn (PlannedRequirement $item): bool => $item->sku()->sku_code === $currentSkuCode)
            ?? $top;
    }

    /**
     * @param  array<int, string|null>  $lastSkuByMachine
     */
    private function changeoverMinutesFor(
        CapacitySlot $slot,
        PlannedRequirement $candidate,
        array $lastSkuByMachine,
    ): int {
        $previous = $lastSkuByMachine[$slot->machine->id] ?? null;

        if ($previous === null || $previous === $candidate->sku()->sku_code) {
            return 0;
        }

        return $this->runRates->resolve($slot->machine, $candidate->sku())?->changeoverMinutes ?? 0;
    }

    private function capacityFor(
        CapacitySlot $slot,
        PlannedRequirement $candidate,
        int $changeoverMinutes,
        string $fraction,
    ): ?CapacityResult {
        $rate = $this->runRates->resolve($slot->machine, $candidate->sku());

        if ($rate === null) {
            return null;
        }

        try {
            return $this->capacity->calculate(
                shift: $slot->shift,
                sku: $candidate->sku(),
                oeeSetting: $slot->oeeSetting,
                runRatePerHour: $rate->ratePerHour,
                durationMinutes: $slot->durationMinutes,
                downtimeMinutes: $slot->downtimeMinutes + $changeoverMinutes,
                shiftFraction: $fraction,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The share of a shift needed to make the outstanding quantity, rounded up to four places so
     * a part shift is never short.
     */
    private function fractionNeeded(int $remaining, int $fullShiftPipes): string
    {
        if ($fullShiftPipes <= 0) {
            return '1.0000';
        }

        $exact = bcdiv((string) $remaining, (string) $fullShiftPipes, 8);

        if (bccomp($exact, '1.0000', 8) >= 0) {
            return '1.0000';
        }

        $truncated = bcadd($exact, '0', 4);
        $rounded = bccomp($exact, $truncated, 8) > 0 ? bcadd($truncated, '0.0001', 4) : $truncated;

        return bccomp($rounded, '0.0001', 4) < 0 ? '0.0001' : $rounded;
    }

    /**
     * The window a run occupies, measured in minutes from the start of the shift.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function windowFor(CapacitySlot $slot, string $alreadyUsed, string $fraction): array
    {
        $shiftStart = $slot->date->setTimeFromTimeString((string) $slot->shift->starts_at);
        $startMinutes = (int) round((float) $alreadyUsed * $slot->durationMinutes);
        $endMinutes = (int) round((float) bcadd($alreadyUsed, $fraction, 4) * $slot->durationMinutes);

        return [
            $shiftStart->addMinutes($startMinutes),
            $shiftStart->addMinutes(max($startMinutes + 1, $endMinutes)),
        ];
    }

    private function oeePercentFor(CapacitySlot $slot): string
    {
        $performance = (float) ($slot->oeeSetting?->performance_percent ?? $slot->shift->performance_target_percent);
        $availability = (float) ($slot->oeeSetting?->availability_percent ?? $slot->shift->availability_target_percent);

        return number_format($performance * $availability / 100, 2, '.', '');
    }

    private function recordChangeover(
        CapacitySlot $slot,
        CarbonImmutable $start,
        int $minutes,
        PlannedRequirement $candidate,
    ): void {
        DowntimeEvent::query()->create([
            'depot_id' => $slot->depot->id,
            'machine_asset_id' => $slot->machine->id,
            'shift_id' => $slot->shift->id,
            'starts_at' => $start->subMinutes($minutes),
            'ends_at' => $start,
            'category' => DowntimeEvent::CATEGORY_CHANGEOVER,
            'description' => 'Change-over to '.$candidate->sku()->sku_code,
            'is_planned' => true,
            'origin' => ProductionSchedule::ORIGIN_SYSTEM,
        ]);
    }

    /**
     * Restricts a schedule query to the plan horizon.
     *
     * schedule_date is cast to a date, which Eloquent persists with a midnight time, so a bare
     * 'Y-m-d' upper bound sorts before every row on that day and silently drops the last day of
     * the horizon. Left uncorrected, regenerating leaves the final day's lines behind and books
     * the machines twice.
     *
     * @param  Builder<ProductionSchedule>  $query
     */
    private function withinHorizon(Builder $query, PlanRequest $request): void
    {
        $query->whereBetween('schedule_date', [$request->from->startOfDay(), $request->to->endOfDay()]);
    }

    /**
     * @return Collection<int, Depot>
     */
    private function depotsFor(PlanRequest $request): Collection
    {
        return Depot::query()
            ->with(['shifts.patterns', 'machines'])
            ->where('is_planning_enabled', true)
            ->when($request->depotIds !== null, fn ($query) => $query->whereKey($request->depotIds))
            ->orderBy('code')
            ->get();
    }

    /**
     * Removes the lines a previous run generated that nobody has claimed, along with the
     * change-overs they implied, so regenerating is idempotent.
     *
     * @param  Collection<int, Depot>  $depots
     */
    private function clearReplaceableLines(Collection $depots, PlanRequest $request): void
    {
        $depotIds = $depots->pluck('id');

        ProductionSchedule::query()
            ->whereIn('depot_id', $depotIds)
            ->tap(fn ($query) => $this->withinHorizon($query, $request))
            ->where('origin', ProductionSchedule::ORIGIN_SYSTEM)
            ->where('is_locked', false)
            ->delete();

        DowntimeEvent::query()
            ->whereIn('depot_id', $depotIds)
            ->where('category', DowntimeEvent::CATEGORY_CHANGEOVER)
            ->where('origin', ProductionSchedule::ORIGIN_SYSTEM)
            ->whereBetween('starts_at', [$request->from->startOfDay(), $request->to->endOfDay()])
            ->delete();
    }

    /**
     * @param  Collection<int, Depot>  $depots
     * @return array<int, Collection<int, PlannedRequirement>>
     */
    private function outstandingByDepot(Collection $depots, PlanRequest $request): array
    {
        $outstanding = [];

        foreach ($depots as $depot) {
            $profiles = $this->demand->forDepot($depot, $request->asOfDate());
            $this->demand->persist($profiles);

            $outstanding[$depot->id] = $this->requirements
                ->build($profiles, $request->targetMonthsOnHand, $request->includeReplenishment)
                ->values()
                ->map(fn (DemandRequirement $demand, int $index): PlannedRequirement => new PlannedRequirement($demand, $index));
        }

        return $outstanding;
    }

    /**
     * Work a planner has already committed counts against demand, so the plan tops it up rather
     * than scheduling it twice.
     *
     * @param  Collection<int, Depot>  $depots
     * @param  array<int, Collection<int, PlannedRequirement>>  $outstanding
     */
    private function consumeRetainedLines(Collection $depots, PlanRequest $request, array &$outstanding): void
    {
        $retained = ProductionSchedule::query()
            ->with('sku')
            ->whereIn('depot_id', $depots->pluck('id'))
            ->tap(fn ($query) => $this->withinHorizon($query, $request))
            ->get();

        foreach ($retained as $line) {
            $queue = $outstanding[$line->depot_id] ?? null;

            if ($queue === null) {
                continue;
            }

            $quantity = $line->planned_quantity;

            foreach ($queue as $item) {
                if ($quantity <= 0) {
                    break;
                }

                if ($item->sku()->id === $line->sku_master_id) {
                    $quantity -= $item->take($quantity);
                }
            }
        }
    }

    /**
     * How much of each slot is already spoken for by lines the planner kept.
     *
     * @param  Collection<int, Depot>  $depots
     * @return array<string, string>
     */
    private function retainedFractionsBySlot(Collection $depots, PlanRequest $request): array
    {
        $fractions = [];

        $lines = ProductionSchedule::query()
            ->whereIn('depot_id', $depots->pluck('id'))
            ->tap(fn ($query) => $this->withinHorizon($query, $request))
            ->get(['depot_id', 'machine_asset_id', 'shift_id', 'schedule_date', 'shift_fraction']);

        foreach ($lines as $line) {
            $key = implode('|', [
                $line->depot_id,
                $line->machine_asset_id,
                $line->shift_id,
                $line->schedule_date->toDateString(),
            ]);

            $fractions[$key] = bcadd($fractions[$key] ?? '0.0000', (string) $line->shift_fraction, 4);
        }

        return $fractions;
    }

    /**
     * @param  Collection<int, Depot>  $depots
     * @param  Collection<int, CapacitySlot>  $slots
     * @param  array<int, Collection<int, PlannedRequirement>>  $outstanding
     * @return array<string, mixed>
     */
    /**
     * What the plan consumes, summarised onto the run so the review screen and the narrative can
     * show it without recomputing.
     *
     * @return array<string, mixed>
     */
    private function materialMetrics(MaterialForecast $materials): array
    {
        return [
            'wire_kg' => $materials->totalWireKg(),
            'wire_tonnes' => $materials->totalWireTonnes(),
            'wire_by_gauge_kg' => $materials->wireByGauge()->all(),
            'concrete_m3' => $materials->totalConcreteM3(),
            'concrete_by_mix_m3' => $materials->concreteByMix()->all(),
        ];
    }

    private function metricsFor(
        PlanRun $run,
        Collection $depots,
        Collection $slots,
        array $outstanding,
        int $lines,
    ): array {
        $schedules = ProductionSchedule::query()
            ->with(['sku', 'depot'])
            ->where('plan_run_id', $run->id)
            ->get();

        $byDepot = [];

        foreach ($depots as $depot) {
            $depotLines = $schedules->where('depot_id', $depot->id);
            $depotSlots = $slots->filter(fn (CapacitySlot $slot): bool => $slot->depot->id === $depot->id);
            $shortfall = ($outstanding[$depot->id] ?? collect())
                ->filter(fn (PlannedRequirement $item): bool => $item->isOutstanding());

            $byDepot[$depot->code] = [
                'planned_eaches' => (int) $depotLines->sum('planned_quantity'),
                'planned_tonnes' => round($depotLines->sum(
                    fn (ProductionSchedule $line): float => $line->plannedTonnes(),
                ), 3),
                'lines' => $depotLines->count(),
                'shift_slots' => $depotSlots->count(),
                'shift_slots_used' => $depotLines->unique(
                    fn (ProductionSchedule $line): string => $line->machine_asset_id.'|'.$line->shift_id.'|'.$line->schedule_date->toDateString(),
                )->count(),
                'unmet_order_eaches' => (int) $shortfall
                    ->filter(fn (PlannedRequirement $item): bool => $item->isOrderBacked())
                    ->sum(fn (PlannedRequirement $item): int => $item->remaining),
                'unmet_replenishment_eaches' => (int) $shortfall
                    ->reject(fn (PlannedRequirement $item): bool => $item->isOrderBacked())
                    ->sum(fn (PlannedRequirement $item): int => $item->remaining),
                'skus_short' => $shortfall
                    ->filter(fn (PlannedRequirement $item): bool => $item->isOrderBacked())
                    ->map(fn (PlannedRequirement $item): string => $item->sku()->sku_code)
                    ->values()
                    ->all(),
            ];
        }

        return [
            'lines' => $lines,
            'planned_eaches' => (int) $schedules->sum('planned_quantity'),
            'planned_tonnes' => round($schedules->sum(fn (ProductionSchedule $line): float => $line->plannedTonnes()), 3),
            'by_depot' => $byDepot,
            'by_month' => $schedules
                ->groupBy(fn (ProductionSchedule $line): string => $line->schedule_date->format('Y-m'))
                ->map(fn (Collection $group): array => [
                    'eaches' => (int) $group->sum('planned_quantity'),
                    'tonnes' => round($group->sum(fn (ProductionSchedule $line): float => $line->plannedTonnes()), 3),
                ])
                ->all(),
        ];
    }
}
