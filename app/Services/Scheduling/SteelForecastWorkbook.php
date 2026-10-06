<?php

namespace App\Services\Scheduling;

use App\Models\Depot;
use App\Models\ProductionSchedule;
use App\Models\SkuWireRequirement;
use App\Models\SteelForecast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The workbook Steel Forecast tab: weeks down, Tencia wire codes across, stocktake and
 * order rows underneath. Weekly figures are tonnes for the week the pipes are made.
 */
final class SteelForecastWorkbook
{
    public const WEEKS = 8;

    private const GRAMS_PER_KG = 1_000;

    private const GRAMS_PER_TONNE = 1_000_000;

    public function __construct(private SteelPlanningService $steel) {}

    /**
     * @param  Collection<int, Depot>  $depots
     * @param  Collection<int, SteelForecast>  $forecasts
     * @param  Builder<ProductionSchedule>  $scheduleQuery
     * @return Collection<int, array<string, mixed>>
     */
    public function tables(Collection $depots, Collection $forecasts, Builder $scheduleQuery): Collection
    {
        if ($depots->isEmpty()) {
            return collect();
        }

        [$periodStart, $periodEnd] = $this->period();

        $schedules = $scheduleQuery
            ->with('sku.wireRequirements')
            ->whereIn('depot_id', $depots->pluck('id')->all())
            ->whereBetween('schedule_date', [$periodStart->startOfDay(), $periodEnd])
            ->where('planned_quantity', '>', 0)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('schedule_date')
            ->orderBy('id')
            ->get();

        $forecastsByDepot = $forecasts->groupBy('depot_id');

        return $depots
            ->sortBy('code')
            ->filter(function (Depot $depot) use ($forecastsByDepot, $schedules): bool {
                return $forecastsByDepot->has($depot->id)
                    || $schedules->contains('depot_id', $depot->id);
            })
            ->map(fn (Depot $depot): array => $this->tableForDepot(
                $depot,
                $forecastsByDepot->get($depot->id, collect()),
                $schedules,
                $periodStart,
            ))
            ->values();
    }

    /**
     * Eight weeks from the Monday of the current week, not from the stocktake date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(): array
    {
        $start = now()->toImmutable()->startOfWeek();

        return [$start, $start->addWeeks(self::WEEKS)->subDay()->endOfDay()];
    }

    /**
     * @param  Collection<int, SteelForecast>  $depotForecasts
     * @param  Collection<int, ProductionSchedule>  $schedules
     * @return array<string, mixed>
     */
    private function tableForDepot(
        Depot $depot,
        Collection $depotForecasts,
        Collection $schedules,
        CarbonImmutable $periodStart,
    ): array {
        $forecastsByWire = $depotForecasts
            ->groupBy('wire_code')
            ->map(fn (Collection $rows): SteelForecast => $rows
                ->sortByDesc(fn (SteelForecast $forecast): string => $forecast->forecast_date->toDateString())
                ->first());

        $depotSchedules = $schedules->where('depot_id', $depot->id);

        $wireCodes = $forecastsByWire->keys()
            ->concat($depotSchedules->flatMap(function (ProductionSchedule $schedule): array {
                return $schedule->sku?->wireRequirements->pluck('wire_code')->all() ?? [];
            }))
            ->filter()
            ->unique()
            ->sortBy(fn (string $code): array => $this->wireColumnOrder($code))
            ->values()
            ->all();

        $weeks = $this->weekStarts($periodStart);
        $weekGrams = [];
        $linesByWire = array_fill_keys($wireCodes, []);

        foreach ($weeks as $week) {
            foreach ($wireCodes as $code) {
                $weekGrams[$week->toDateString()][$code] = 0;
            }
        }

        foreach ($depotSchedules as $schedule) {
            if ($schedule->sku === null) {
                continue;
            }

            $weekKey = CarbonImmutable::parse($schedule->schedule_date)->startOfWeek()->toDateString();

            if (! isset($weekGrams[$weekKey])) {
                continue;
            }

            foreach ($schedule->sku->wireRequirements as $requirement) {
                $code = $requirement->wire_code;

                if (! isset($weekGrams[$weekKey][$code])) {
                    continue;
                }

                $weekGrams[$weekKey][$code] += (int) round((float) $requirement->kg_per_pipe * self::GRAMS_PER_KG)
                    * (int) $schedule->planned_quantity;

                $linesByWire[$code][] = new SteelDemandLine(
                    $depot->id,
                    $schedule->sku,
                    (int) $schedule->planned_quantity,
                    $requirement->wire_code,
                    $requirement->kg_per_pipe,
                );
            }
        }

        $weekRows = [];
        $requirementGrams = array_fill_keys($wireCodes, 0);

        foreach ($weeks as $week) {
            $key = $week->toDateString();
            $tonnes = [];

            foreach ($wireCodes as $code) {
                $grams = $weekGrams[$key][$code];
                $requirementGrams[$code] += $grams;
                $tonnes[$code] = $grams === 0 ? '' : $this->formatTonnes($grams);
            }

            $weekRows[] = [
                'from' => $week,
                'to' => $week->endOfWeek(),
                'tonnes' => $tonnes,
            ];
        }

        $requirement = [];
        $coilsOnHand = [];
        $averageCoilWeight = [];
        $tonnesOnHand = [];
        $onOrder = [];
        $transfersIn = [];
        $endingStock = [];
        $safetyStock = [];
        $plannedOrder = [];
        $urgencies = [];

        foreach ($wireCodes as $code) {
            $requirement[$code] = $this->formatTonnes($requirementGrams[$code]);
            $forecast = $forecastsByWire->get($code);

            if ($forecast === null) {
                $coilsOnHand[$code] = '';
                $averageCoilWeight[$code] = '';
                $tonnesOnHand[$code] = '';
                $onOrder[$code] = '';
                $transfersIn[$code] = '';
                $endingStock[$code] = '';
                $safetyStock[$code] = '';
                $plannedOrder[$code] = '';
                $urgencies[$code] = 'watch';

                continue;
            }

            $weeklyKg = [];

            foreach ($weeks as $week) {
                $kilograms = $weekGrams[$week->toDateString()][$code] / self::GRAMS_PER_KG;

                if ($kilograms > 0) {
                    $weeklyKg[] = $kilograms;
                }
            }

            $result = $this->steel->calculate(
                $linesByWire[$code],
                SteelStockPosition::fromForecast($forecast),
                $weeklyKg,
            );

            $kilogramsPerCoil = (float) $forecast->average_coil_weight_tonnes * 1_000;
            $recommendedCoils = $kilogramsPerCoil > 0
                ? (int) ceil((float) $result->recommendedOrderKg / $kilogramsPerCoil)
                : 0;

            $urgencies[$code] = match (true) {
                $recommendedCoils > 0 && $forecast->forecast_date->isPast() => 'overdue',
                $recommendedCoils > 0 => 'critical',
                (float) $result->projectedEndingKg < (float) $result->safetyStockKg => 'watch',
                default => 'covered',
            };

            $coilGrams = $this->tonnesToGrams($forecast->average_coil_weight_tonnes);

            $coilsOnHand[$code] = $this->formatCoils($forecast->on_hand_coils);
            $averageCoilWeight[$code] = number_format((float) $forecast->average_coil_weight_tonnes, 2, '.', '');
            $tonnesOnHand[$code] = $this->formatTonnes($forecast->on_hand_coils * $coilGrams);
            $onOrder[$code] = $this->formatTonnes($forecast->on_order_coils * $coilGrams);
            $transfersIn[$code] = $this->formatTonnes(
                ($forecast->transfer_in_coils - $forecast->transfer_out_coils) * $coilGrams,
            );
            $endingStock[$code] = $this->formatTonnes($this->kilogramsToGrams($result->projectedEndingKg));
            $safetyStock[$code] = $this->formatTonnes($this->kilogramsToGrams($result->safetyStockKg));
            $plannedOrder[$code] = $this->formatWholeTonnes($this->kilogramsToGrams($result->recommendedOrderKg));
        }

        return [
            'depot' => $depot,
            'wire_codes' => $wireCodes,
            'weeks' => $weekRows,
            'requirement' => $requirement,
            'coils_on_hand' => $coilsOnHand,
            'average_coil_weight' => $averageCoilWeight,
            'tonnes_on_hand' => $tonnesOnHand,
            'on_order' => $onOrder,
            'transfers_in' => $transfersIn,
            'ending_stock' => $endingStock,
            'safety_stock' => $safetyStock,
            'planned_order' => $plannedOrder,
            'urgencies' => $urgencies,
        ];
    }

    /**
     * Workbook columns are WIREPL then WIREDE, each by gauge, matching the Steel Forecast tab.
     *
     * @return array{0: int, 1: float, 2: string}
     */
    private function wireColumnOrder(string $wireCode): array
    {
        $family = match (true) {
            str_starts_with($wireCode, 'WIREPL') => 0,
            str_starts_with($wireCode, 'WIREDE') => 1,
            default => 2,
        };

        return [$family, SkuWireRequirement::gaugeFromWireCode($wireCode) ?? PHP_FLOAT_MAX, $wireCode];
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function weekStarts(CarbonImmutable $periodStart): array
    {
        $weeks = [];

        for ($offset = 0; $offset < self::WEEKS; $offset++) {
            $weeks[] = $periodStart->addWeeks($offset)->startOfWeek();
        }

        return $weeks;
    }

    private function kilogramsToGrams(int|float|string $kilograms): int
    {
        return (int) round((float) $kilograms * self::GRAMS_PER_KG);
    }

    private function tonnesToGrams(int|float|string $tonnes): int
    {
        return (int) round((float) $tonnes * self::GRAMS_PER_TONNE);
    }

    private function formatTonnes(int $grams): string
    {
        return number_format($grams / self::GRAMS_PER_TONNE, 1, '.', '');
    }

    private function formatWholeTonnes(int $grams): string
    {
        return (string) (int) round($grams / self::GRAMS_PER_TONNE);
    }

    private function formatCoils(int $coils): string
    {
        return number_format($coils, 1, '.', '');
    }
}
