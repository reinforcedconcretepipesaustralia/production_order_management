<?php

namespace App\Services\Planning;

use App\Models\DemandForecast;
use App\Models\Depot;
use App\Models\InventorySoh;
use App\Models\SalesHistoryMonthly;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use App\Services\Sync\OrderSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the demand picture per plant per SKU: how fast it sells, what is on order, what is in
 * the yard, and therefore what needs making.
 *
 * This is the 'Pipes to Manufacture' sheet as a service. The workbook does the same work with a
 * SUMIFS per cell across a 16,000-row sales extract and a 1,200-row order book, and needs a
 * human to re-sort it every month to keep the fast movers at the top.
 */
final class DemandModel
{
    /** Share of eaches that defines the fast movers, per the workbook's 80/20 split. */
    public const FAST_MOVER_SHARE = 0.8;

    /** Months of cover held for a fast mover when the SKU carries no target of its own. */
    public const DEFAULT_TARGET_MONTHS_ON_HAND = 1.0;

    /** Delivery months bucketed out, matching workbook columns V to Y. */
    private const FORWARD_MONTHS = 4;

    public function __construct(private OrderSync $orderBook) {}

    /**
     * @return Collection<int, SkuDemandProfile> Keyed by SKU id, ordered fastest mover first.
     */
    public function forDepot(Depot $depot, ?CarbonImmutable $asOf = null): Collection
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->startOfDay();

        $velocity = $this->velocityBySku($depot, $asOf);
        $orderBook = $this->orderBookBySku($depot, $asOf);
        $stock = $this->stockBySku($depot);

        $skuIds = $velocity->keys()
            ->merge($orderBook->keys())
            ->merge($stock->keys())
            ->unique()
            ->values();

        if ($skuIds->isEmpty()) {
            return collect();
        }

        /** @var Collection<int, SkuMaster> $skus */
        $skus = SkuMaster::query()->whereKey($skuIds)->get()->keyBy('id');

        $profiles = $skuIds
            ->filter(fn (int $skuId): bool => $skus->has($skuId))
            ->map(fn (int $skuId): SkuDemandProfile => $this->buildProfile(
                $depot,
                $skus[$skuId],
                $asOf,
                $velocity[$skuId] ?? [],
                $orderBook[$skuId] ?? [],
                $stock[$skuId] ?? [],
            ));

        return $this->classifyFastMovers($profiles);
    }

    /**
     * Persists a snapshot so a plan run can be reproduced from the figures it was built on.
     *
     * @param  Collection<int, SkuDemandProfile>  $profiles
     */
    public function persist(Collection $profiles): int
    {
        $written = 0;

        foreach ($profiles as $profile) {
            DemandForecast::query()->updateOrCreate(
                [
                    'depot_id' => $profile->depotId,
                    'sku_master_id' => $profile->sku->id,
                    // Passed as a date instance, not a string: the 'date' cast persists
                    // midnight alongside the date, so a bare 'Y-m-d' would never match an
                    // existing row and every re-run would try to insert a duplicate.
                    'as_of_date' => $profile->asOf,
                ],
                [
                    'r3m_monthly_ea' => $profile->r3mMonthlyEa,
                    'r6m_monthly_ea' => $profile->r6mMonthlyEa,
                    'r12m_monthly_ea' => $profile->r12mMonthlyEa,
                    'outstanding_qty' => $profile->outstandingQty,
                    'overdue_qty' => $profile->overdueQty,
                    'current_month_qty' => $profile->currentMonthQty,
                    'outstanding_by_month' => $profile->outstandingByMonth,
                    'earliest_due_date' => $profile->earliestDueDate?->toDateString(),
                    'prime_soh_qty' => $profile->primeSohQty,
                    'rework_soh_qty' => $profile->reworkSohQty,
                    'net_position_qty' => $profile->netPositionQty(),
                    'months_on_hand' => $profile->monthsOnHand(),
                    'equivalent_months' => $profile->equivalentMonths(),
                    'has_demand_signal' => $profile->hasDemandSignal(),
                    'abc_class' => $profile->abcClass,
                    'abc_cumulative_share' => $profile->abcCumulativeShare,
                    'order_backed_qty' => $profile->orderBackedQty(),
                    'replenishment_qty' => $profile->replenishmentQty(self::DEFAULT_TARGET_MONTHS_ON_HAND),
                ],
            );

            $written++;
        }

        return $written;
    }

    /**
     * Writes the fast-mover classification back onto the SKUs, so the rest of the app can ask a
     * SKU whether it is made to stock without reloading a forecast.
     *
     * @param  Collection<int, SkuDemandProfile>  $profiles
     */
    public function applyClassificationToSkus(Collection $profiles): void
    {
        foreach ($profiles->groupBy(fn (SkuDemandProfile $profile): string => $profile->abcClass ?? 'C') as $class => $group) {
            SkuMaster::query()
                ->whereKey($group->map(fn (SkuDemandProfile $profile): int => $profile->sku->id)->all())
                ->update(['abc_class' => $class]);
        }
    }

    /**
     * @param  array{r3m?: float, r6m?: float, r12m?: float}  $velocity
     * @param  array{outstanding?: int, overdue?: int, current_month?: int, by_month?: array<string, int>, earliest_due?: string|null}  $orders
     * @param  array{prime?: int, rework?: int}  $stock
     */
    private function buildProfile(
        Depot $depot,
        SkuMaster $sku,
        CarbonImmutable $asOf,
        array $velocity,
        array $orders,
        array $stock,
    ): SkuDemandProfile {
        return new SkuDemandProfile(
            depotId: $depot->id,
            sku: $sku,
            asOf: $asOf,
            r3mMonthlyEa: $velocity['r3m'] ?? 0.0,
            r6mMonthlyEa: $velocity['r6m'] ?? 0.0,
            r12mMonthlyEa: $velocity['r12m'] ?? 0.0,
            outstandingQty: $orders['outstanding'] ?? 0,
            overdueQty: $orders['overdue'] ?? 0,
            currentMonthQty: $orders['current_month'] ?? 0,
            outstandingByMonth: $orders['by_month'] ?? [],
            earliestDueDate: isset($orders['earliest_due'])
                ? CarbonImmutable::parse($orders['earliest_due'])
                : null,
            primeSohQty: $stock['prime'] ?? 0,
            reworkSohQty: $stock['rework'] ?? 0,
        );
    }

    /**
     * Average monthly eaches over the trailing 3, 6 and 12 whole months.
     *
     * @return Collection<int, array{r3m: float, r6m: float, r12m: float}>
     */
    private function velocityBySku(Depot $depot, CarbonImmutable $asOf): Collection
    {
        $rows = SalesHistoryMonthly::query()
            ->where('depot_id', $depot->id)
            ->trailingMonths($asOf, 12)
            ->get(['sku_master_id', 'month', 'quantity_ea']);

        $firstOfCurrentMonth = $asOf->startOfMonth();

        return $rows
            ->groupBy('sku_master_id')
            ->map(function (Collection $skuRows) use ($firstOfCurrentMonth): array {
                $sum = function (int $months) use ($skuRows, $firstOfCurrentMonth): float {
                    $from = $firstOfCurrentMonth->subMonths($months);

                    return (float) $skuRows
                        ->filter(fn (SalesHistoryMonthly $row): bool => $row->month->greaterThanOrEqualTo($from))
                        ->sum('quantity_ea');
                };

                return [
                    'r3m' => $sum(3) / 3,
                    'r6m' => $sum(6) / 6,
                    'r12m' => $sum(12) / 12,
                ];
            });
    }

    /**
     * Outstanding order quantities, split into overdue, the current month, and the next few
     * delivery months.
     *
     * @return Collection<int, array{outstanding: int, overdue: int, current_month: int, by_month: array<string, int>, earliest_due: string|null}>
     */
    private function orderBookBySku(Depot $depot, CarbonImmutable $asOf): Collection
    {
        $endOfHorizon = $asOf->startOfMonth()->addMonths(self::FORWARD_MONTHS)->subDay();

        return $this->orderBook->lines()
            ->filter(fn (array $line): bool => $line['depot_id'] === $depot->id
                && in_array($line['status'], ['open', 'partially_allocated'], true)
                && $line['allocated_quantity'] < $line['ordered_quantity'])
            ->map(fn (array $line): SalesOrder => SalesOrder::make($line))
            ->groupBy('sku_master_id')
            ->map(function (Collection $orders) use ($asOf, $endOfHorizon): array {
                $byMonth = [];
                $outstanding = 0;
                $overdue = 0;
                $currentMonth = 0;
                $earliestDue = null;

                foreach ($orders as $order) {
                    $quantity = max(0, $order->ordered_quantity - $order->allocated_quantity);

                    if ($quantity === 0) {
                        continue;
                    }

                    $due = CarbonImmutable::instance($order->requested_delivery_date);
                    $outstanding += $quantity;

                    if ($earliestDue === null || $due->lessThan($earliestDue)) {
                        $earliestDue = $due;
                    }

                    // Anything already late is pulled into the current month, as the workbook's
                    // delivery-month bucket does, so it competes for this month's capacity.
                    if ($due->lessThan($asOf->startOfMonth())) {
                        $overdue += $quantity;
                        $currentMonth += $quantity;
                        $bucket = $asOf->startOfMonth();
                    } else {
                        $bucket = $due->startOfMonth();

                        if ($bucket->equalTo($asOf->startOfMonth())) {
                            $currentMonth += $quantity;
                        }
                    }

                    if ($bucket->lessThanOrEqualTo($endOfHorizon)) {
                        $key = $bucket->toDateString();
                        $byMonth[$key] = ($byMonth[$key] ?? 0) + $quantity;
                    }
                }

                ksort($byMonth);

                return [
                    'outstanding' => $outstanding,
                    'overdue' => $overdue,
                    'current_month' => $currentMonth,
                    'by_month' => $byMonth,
                    'earliest_due' => $earliestDue?->toDateString(),
                ];
            });
    }

    /**
     * @return Collection<int, array{prime: int, rework: int}>
     */
    private function stockBySku(Depot $depot): Collection
    {
        return InventorySoh::query()
            ->where('depot_id', $depot->id)
            ->get(['sku_master_id', 'prime_quantity', 'rework_quantity'])
            ->groupBy('sku_master_id')
            ->map(fn (Collection $rows): array => [
                'prime' => (int) $rows->sum('prime_quantity'),
                'rework' => (int) $rows->sum('rework_quantity'),
            ]);
    }

    /**
     * Ranks SKUs by recent volume and marks the top 80% of eaches as fast movers — the products
     * the plants aim to hold in stock. Everything else is made to order.
     *
     * @param  Collection<int, SkuDemandProfile>  $profiles
     * @return Collection<int, SkuDemandProfile>
     */
    private function classifyFastMovers(Collection $profiles): Collection
    {
        $ranked = $profiles->sortByDesc(fn (SkuDemandProfile $profile): float => $profile->r3mMonthlyEa)->values();
        $total = (float) $ranked->sum(fn (SkuDemandProfile $profile): float => $profile->r3mMonthlyEa);

        if ($total <= 0.0) {
            return $ranked
                ->map(fn (SkuDemandProfile $profile): SkuDemandProfile => $this->withClassification($profile, 'C', 0.0))
                ->keyBy(fn (SkuDemandProfile $profile): int => $profile->sku->id);
        }

        $cumulative = 0.0;
        $crossedThreshold = false;

        return $ranked
            ->map(function (SkuDemandProfile $profile) use ($total, &$cumulative, &$crossedThreshold): SkuDemandProfile {
                if ($profile->r3mMonthlyEa <= 0.0) {
                    return $this->withClassification($profile, 'C', $cumulative / $total);
                }

                $cumulative += $profile->r3mMonthlyEa;
                $share = $cumulative / $total;

                // The SKU that carries the cumulative share past 80% is itself a fast mover;
                // everything after it is not. Without this the largest seller could be excluded
                // when a single SKU accounts for more than 80% on its own.
                $class = $crossedThreshold ? 'B' : 'A';

                if ($share >= self::FAST_MOVER_SHARE) {
                    $crossedThreshold = true;
                }

                return $this->withClassification($profile, $class, $share);
            })
            ->keyBy(fn (SkuDemandProfile $profile): int => $profile->sku->id);
    }

    private function withClassification(SkuDemandProfile $profile, string $class, float $share): SkuDemandProfile
    {
        return new SkuDemandProfile(
            depotId: $profile->depotId,
            sku: $profile->sku,
            asOf: $profile->asOf,
            r3mMonthlyEa: $profile->r3mMonthlyEa,
            r6mMonthlyEa: $profile->r6mMonthlyEa,
            r12mMonthlyEa: $profile->r12mMonthlyEa,
            outstandingQty: $profile->outstandingQty,
            overdueQty: $profile->overdueQty,
            currentMonthQty: $profile->currentMonthQty,
            outstandingByMonth: $profile->outstandingByMonth,
            earliestDueDate: $profile->earliestDueDate,
            primeSohQty: $profile->primeSohQty,
            reworkSohQty: $profile->reworkSohQty,
            abcClass: $class,
            abcCumulativeShare: $share,
        );
    }
}
