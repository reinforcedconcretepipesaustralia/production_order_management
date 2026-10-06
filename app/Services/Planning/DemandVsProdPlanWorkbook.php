<?php

namespace App\Services\Planning;

use App\Models\Depot;
use App\Models\InventorySoh;
use App\Models\ProductionSchedule;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Demand vs Prod Plan sheet: outstanding orders against the production plan,
 * month by month, with current stock beside the gap.
 */
final class DemandVsProdPlanWorkbook
{
    public const MONTHS = 6;

    /**
     * @param  Collection<int, Depot>  $depots
     * @param  Builder<ProductionSchedule>  $scheduleQuery
     * @return array{
     *     months: list<CarbonImmutable>,
     *     rows: list<array{
     *         sku_id: int,
     *         sku_code: string,
     *         soh: int,
     *         demand: array<string, int>,
     *         plan: array<string, int>,
     *         variance: array<string, int>
     *     }>,
     *     totals: array{soh: int, demand: array<string, int>, plan: array<string, int>, variance: array<string, int>}
     * }
     */
    public function table(Collection $depots, Builder $scheduleQuery, ?CarbonImmutable $asOf = null): array
    {
        $asOf = ($asOf ?? now()->toImmutable())->startOfMonth();
        $months = $this->months($asOf);
        $monthKeys = array_map(fn (CarbonImmutable $month): string => $month->toDateString(), $months);
        $emptyByMonth = array_fill_keys($monthKeys, 0);

        if ($depots->isEmpty()) {
            return [
                'months' => $months,
                'rows' => [],
                'totals' => [
                    'soh' => 0,
                    'demand' => $emptyByMonth,
                    'plan' => $emptyByMonth,
                    'variance' => $emptyByMonth,
                ],
            ];
        }

        $depotIds = $depots->pluck('id')->all();
        $horizonEnd = $asOf->addMonths(self::MONTHS)->subDay()->endOfDay();

        $sohBySku = InventorySoh::query()
            ->whereIn('depot_id', $depotIds)
            ->get()
            ->groupBy('sku_master_id')
            ->map(fn (Collection $rows): int => (int) $rows->sum('prime_quantity'));

        $demandBySku = $this->demandBySku($depotIds, $asOf, $monthKeys);
        $planBySku = $this->planBySku($scheduleQuery, $depotIds, $asOf, $horizonEnd, $monthKeys);

        $skuIds = $sohBySku->keys()
            ->merge($demandBySku->keys())
            ->merge($planBySku->keys())
            ->unique()
            ->filter(fn (mixed $skuId): bool => (int) $skuId > 0)
            ->values();

        $codes = SkuMaster::query()
            ->whereKey($skuIds->all())
            ->orderBy('sku_code')
            ->pluck('sku_code', 'id');

        $rows = [];
        $totals = [
            'soh' => 0,
            'demand' => $emptyByMonth,
            'plan' => $emptyByMonth,
            'variance' => $emptyByMonth,
        ];

        foreach ($codes as $skuId => $skuCode) {
            $demand = $demandBySku->get($skuId, $emptyByMonth);
            $plan = $planBySku->get($skuId, $emptyByMonth);
            $soh = $sohBySku->get($skuId, 0);
            $variance = [];

            foreach ($monthKeys as $key) {
                $variance[$key] = $plan[$key] - $demand[$key];
            }

            if ($soh === 0 && array_sum($demand) === 0 && array_sum($plan) === 0) {
                continue;
            }

            $rows[] = [
                'sku_id' => (int) $skuId,
                'sku_code' => (string) $skuCode,
                'soh' => $soh,
                'demand' => $demand,
                'plan' => $plan,
                'variance' => $variance,
            ];

            $totals['soh'] += $soh;

            foreach ($monthKeys as $key) {
                $totals['demand'][$key] += $demand[$key];
                $totals['plan'][$key] += $plan[$key];
                $totals['variance'][$key] += $variance[$key];
            }
        }

        return [
            'months' => $months,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function months(CarbonImmutable $asOf): array
    {
        $months = [];

        for ($offset = 0; $offset < self::MONTHS; $offset++) {
            $months[] = $asOf->startOfMonth()->addMonths($offset);
        }

        return $months;
    }

    /**
     * @param  list<int>  $depotIds
     * @param  list<string>  $monthKeys
     * @return Collection<int, array<string, int>>
     */
    private function demandBySku(array $depotIds, CarbonImmutable $asOf, array $monthKeys): Collection
    {
        $emptyByMonth = array_fill_keys($monthKeys, 0);
        $currentMonth = $asOf->toDateString();
        $horizonEnd = $asOf->addMonths(self::MONTHS)->subDay();

        return SalesOrder::query()
            ->whereIn('depot_id', $depotIds)
            ->whereIn('status', ['open', 'partially_allocated'])
            ->whereColumn('allocated_quantity', '<', 'ordered_quantity')
            ->orderBy('id')
            ->get()
            ->groupBy('sku_master_id')
            ->map(function (Collection $orders) use ($asOf, $currentMonth, $horizonEnd, $emptyByMonth): array {
                $demand = $emptyByMonth;

                foreach ($orders as $order) {
                    $quantity = max(0, (int) $order->ordered_quantity - (int) $order->allocated_quantity);

                    if ($quantity === 0 || $order->requested_delivery_date === null) {
                        continue;
                    }

                    $due = CarbonImmutable::parse($order->requested_delivery_date)->startOfMonth();
                    $bucket = $due->lessThan($asOf) ? $currentMonth : $due->toDateString();

                    if ($due->greaterThan($horizonEnd) && $bucket !== $currentMonth) {
                        continue;
                    }

                    if (! array_key_exists($bucket, $demand)) {
                        continue;
                    }

                    $demand[$bucket] += $quantity;
                }

                return $demand;
            });
    }

    /**
     * @param  Builder<ProductionSchedule>  $scheduleQuery
     * @param  list<int>  $depotIds
     * @param  list<string>  $monthKeys
     * @return Collection<int, array<string, int>>
     */
    private function planBySku(
        Builder $scheduleQuery,
        array $depotIds,
        CarbonImmutable $asOf,
        CarbonImmutable $horizonEnd,
        array $monthKeys,
    ): Collection {
        $emptyByMonth = array_fill_keys($monthKeys, 0);

        return (clone $scheduleQuery)
            ->whereIn('depot_id', $depotIds)
            ->whereNot('status', 'cancelled')
            ->where('planned_quantity', '>', 0)
            ->whereBetween('schedule_date', [$asOf->startOfDay(), $horizonEnd])
            ->orderBy('id')
            ->get()
            ->groupBy('sku_master_id')
            ->map(function (Collection $lines) use ($emptyByMonth): array {
                $plan = $emptyByMonth;

                foreach ($lines as $line) {
                    if ($line->schedule_date === null) {
                        continue;
                    }

                    $key = CarbonImmutable::parse($line->schedule_date)->startOfMonth()->toDateString();

                    if (! array_key_exists($key, $plan)) {
                        continue;
                    }

                    $plan[$key] += (int) $line->planned_quantity;
                }

                return $plan;
            });
    }
}
