<?php

namespace App\Services\Sync;

use App\Models\Depot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Previous production runs, from dbo.vwProduction. Replaces the workbook's 'Production' tab.
 *
 * The view is BOM-exploded: one row per production order *per component*, up to 24 rows for a single
 * order, with GOOD_PIPES, REJECTED_PIPES and BILL_TONNES_PRODUCED repeated verbatim on each. Summing
 * them over the view multiplies production by the number of components, so the query groups back to
 * the order and takes each value once.
 *
 * PROD_ORD_NBR is not unique on its own — it recurs with a different duplicate and a different
 * good-pipe count — so the grain is (ENTITY, PROD_ORD_NBR, PROD_ORD_DUPLICATE). Nothing is written
 * to the local database.
 */
final class ProductionHistorySync
{
    /** Only closed runs are history; the rest are still open or were cancelled. */
    private const COMPLETED = 'Order Completed';

    private ?SyncResult $lastResult = null;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $runs = null;

    public function __construct(
        private TenciaSource $source,
        private ProductSync $products,
    ) {}

    /**
     * Closed production runs, read from Tencia and not stored.
     *
     * @return Collection<int, array{erp_entity_code: string, erp_order_number: string, erp_order_duplicate: int, depot_id: int|null, sku_master_id: int|null, sku_code: string, produced_on: string, good_quantity: int, rejected_quantity: int, tonnes_produced: float, quality_percent: float|null, status: string}>
     */
    public function runs(int $months = 24): Collection
    {
        if ($this->runs !== null) {
            return $this->runs;
        }

        $result = new SyncResult;
        $from = CarbonImmutable::now()->startOfMonth()->subMonths(max(1, $months));
        $rows = [];

        foreach ($this->source->plannedEntities() as $entity) {
            $depots = Depot::query()->where('erp_entity_code', $entity)->get();

            if ($depots->isEmpty()) {
                // A holding yard manufactures nothing, and Tencia has no production rows for it.
                continue;
            }

            $singleDepot = $depots->count() === 1 ? $depots->first() : null;

            foreach ($this->rowsFor($entity, $from) as $row) {
                $code = trim((string) $row->BILL_STOCK_CODE);
                $orderNumber = trim((string) $row->PROD_ORD_NBR);

                if ($code === '' || $orderNumber === '') {
                    continue;
                }

                $producedOn = $this->date($row->ORDER_DATE);

                if ($producedOn === null) {
                    $result->reject(
                        'skipped (no date)',
                        "$entity order $orderNumber has no readable order date.",
                    );

                    continue;
                }

                $sku = $this->products->find($entity, $code);
                $good = max(0, (int) round((float) $row->good_pipes));
                $rejected = max(0, (int) round((float) $row->rejected_pipes));

                $rows[] = [
                    'erp_entity_code' => $entity,
                    'erp_order_number' => $orderNumber,
                    'erp_order_duplicate' => (int) $row->PROD_ORD_DUPLICATE,
                    'depot_id' => $singleDepot?->id,
                    'sku_master_id' => $sku?->id,
                    'sku_code' => $code,
                    'produced_on' => $producedOn->toDateString(),
                    'good_quantity' => $good,
                    'rejected_quantity' => $rejected,
                    'tonnes_produced' => round((float) $row->tonnes_produced, 4),
                    'quality_percent' => $this->qualityPercent($good, $rejected),
                    'status' => (string) $row->STATUS,
                ];
                $result->count('production runs');

                if ($sku === null) {
                    $result->count('runs of a product not in the master');
                }
            }
        }

        $this->lastResult = $result;

        return $this->runs = collect($rows);
    }

    public function result(): SyncResult
    {
        $this->runs();

        return $this->lastResult ?? new SyncResult;
    }

    /**
     * @return Collection<int, object>
     */
    private function rowsFor(string $entity, CarbonImmutable $from): Collection
    {
        return $this->source->view('vwProduction')
            ->where('ENTITY', $entity)
            ->where('STATUS', self::COMPLETED)
            ->where('ORDER_DATE', '>=', $from->toDateTimeString())
            ->groupBy('PROD_ORD_NBR', 'PROD_ORD_DUPLICATE', 'BILL_STOCK_CODE', 'ORDER_DATE', 'STATUS')
            ->orderBy('ORDER_DATE')
            ->get([
                'PROD_ORD_NBR', 'PROD_ORD_DUPLICATE', 'BILL_STOCK_CODE', 'ORDER_DATE', 'STATUS',
                DB::raw('MAX(GOOD_PIPES) AS good_pipes'),
                DB::raw('MAX(REJECTED_PIPES) AS rejected_pipes'),
                DB::raw('MAX(BILL_TONNES_PRODUCED) AS tonnes_produced'),
            ]);
    }

    private function qualityPercent(int $good, int $rejected): ?float
    {
        $made = $good + $rejected;

        return $made === 0 ? null : round($good / $made * 100, 2);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
