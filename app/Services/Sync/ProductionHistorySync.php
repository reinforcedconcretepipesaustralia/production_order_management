<?php

namespace App\Services\Sync;

use App\Models\Depot;
use App\Models\ProductionHistory;
use App\Models\SkuMaster;
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
 * good-pipe count — so the grain is (ENTITY, PROD_ORD_NBR, PROD_ORD_DUPLICATE).
 */
final class ProductionHistorySync
{
    /** Only closed runs are history; the rest are still open or were cancelled. */
    private const COMPLETED = 'Order Completed';

    public function __construct(private TenciaSource $source) {}

    public function sync(int $months = 24): SyncResult
    {
        $result = new SyncResult;
        $skus = $this->skusByEntityAndCode();
        // Tencia holds orders dated back to 1946, so the window is set here rather than trusted.
        $from = CarbonImmutable::now()->startOfMonth()->subMonths(max(1, $months));

        foreach ($this->source->plannedEntities() as $entity) {
            $depots = Depot::query()->where('erp_entity_code', $entity)->get();

            if ($depots->isEmpty()) {
                // A holding yard manufactures nothing, and Tencia has no production rows for it.
                continue;
            }

            $this->syncEntity($entity, $depots, $skus, $from, $result);
        }

        return $result;
    }

    /**
     * @param  Collection<int, Depot>  $depots
     * @param  Collection<string, SkuMaster>  $skus
     */
    private function syncEntity(
        string $entity,
        Collection $depots,
        Collection $skus,
        CarbonImmutable $from,
        SyncResult $result,
    ): void {
        $rows = $this->source->view('vwProduction')
            ->where('ENTITY', $entity)
            ->where('STATUS', self::COMPLETED)
            ->where('ORDER_DATE', '>=', $from->toDateTimeString())
            // One row per order, not per component: the header values repeat across components.
            ->groupBy('PROD_ORD_NBR', 'PROD_ORD_DUPLICATE', 'BILL_STOCK_CODE', 'ORDER_DATE', 'STATUS')
            ->orderBy('ORDER_DATE')
            ->get([
                'PROD_ORD_NBR', 'PROD_ORD_DUPLICATE', 'BILL_STOCK_CODE', 'ORDER_DATE', 'STATUS',
                DB::raw('MAX(GOOD_PIPES) AS good_pipes'),
                DB::raw('MAX(REJECTED_PIPES) AS rejected_pipes'),
                DB::raw('MAX(BILL_TONNES_PRODUCED) AS tonnes_produced'),
            ]);

        $singleDepot = $depots->count() === 1 ? $depots->first() : null;

        foreach ($rows as $row) {
            $code = trim((string) $row->BILL_STOCK_CODE);
            $orderNumber = trim((string) $row->PROD_ORD_NBR);

            if ($code === '' || $orderNumber === '') {
                continue;
            }

            $sku = $skus->get($entity.'|'.$code);
            $producedOn = $this->date($row->ORDER_DATE);

            if ($producedOn === null) {
                $result->reject(
                    'skipped (no date)',
                    "$entity order $orderNumber has no readable order date.",
                );

                continue;
            }

            ProductionHistory::query()->updateOrCreate(
                [
                    'erp_entity_code' => $entity,
                    'erp_order_number' => $orderNumber,
                    'erp_order_duplicate' => (int) $row->PROD_ORD_DUPLICATE,
                ],
                [
                    // Which of a state's two plants ran it is not in the view; only a single-plant
                    // state can be attributed, and the rest is left for the run to say.
                    'depot_id' => $singleDepot?->id,
                    'sku_master_id' => $sku?->id,
                    'sku_code' => $code,
                    'produced_on' => $producedOn,
                    'good_quantity' => max(0, (int) round((float) $row->good_pipes)),
                    'rejected_quantity' => max(0, (int) round((float) $row->rejected_pipes)),
                    'tonnes_produced' => round((float) $row->tonnes_produced, 4),
                    'status' => (string) $row->STATUS,
                ],
            );

            $result->count('production runs');

            if ($sku === null) {
                $result->count('runs of a product not in the master');
            }
        }
    }

    /**
     * @return Collection<string, SkuMaster> Keys are "ENTITY|STOCK_CODE".
     */
    private function skusByEntityAndCode(): Collection
    {
        return SkuMaster::query()
            ->whereNotNull('erp_entity_code')
            ->get(['id', 'sku_code', 'erp_entity_code'])
            ->keyBy(fn (SkuMaster $sku): string => $sku->erp_entity_code.'|'.$sku->sku_code);
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
