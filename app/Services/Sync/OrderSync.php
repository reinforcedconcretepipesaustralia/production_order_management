<?php

namespace App\Services\Sync;

use App\Models\Depot;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The outstanding order book — what the priority rule ranks on.
 *
 * Reads SOR_LINES joined SOR_MASTER in each state's own database, because the consolidated
 * vwSALES_ORDERS denies SELECT to the read-only login. The filter is the one the workbooks' Power
 * Query used, so the two agree: open orders only, cancelled lines excluded.
 *
 * Supersedes OrderBookImporter, which reads a manual Excel extract and is only as current as its
 * last refresh.
 */
final class OrderSync
{
    public function __construct(
        private TenciaSource $source,
        private HoldingYardRouter $router,
    ) {}

    public function sync(bool $replace = true): SyncResult
    {
        $result = new SyncResult;
        $depotsByLocation = $this->source->depotsByStockLocation();
        $depotsByEntity = Depot::query()->whereNotNull('erp_entity_code')->get()->groupBy('erp_entity_code');
        $skus = $this->skusByEntityAndCode();
        $categories = $this->categoriesByEntityAndCode();

        $rows = [];

        foreach ($this->source->plannedEntities() as $entity) {
            foreach ($this->openLinesFor($entity) as $line) {
                $row = $this->rowFor($entity, $line, $skus, $categories, $depotsByLocation, $depotsByEntity, $result);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        if ($replace) {
            SalesOrder::query()->delete();
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            SalesOrder::query()->upsert($chunk, ['erp_order_reference', 'erp_line_reference']);
        }

        return $result;
    }

    /**
     * Open order lines, exactly as the workbook's query defined "outstanding".
     *
     * @return Collection<int, object>
     */
    private function openLinesFor(string $entity): Collection
    {
        return $this->source->stateTable($entity, 'SOR_LINES', 'l')
            ->join(
                DB::raw(
                    $this->source->stateTableName($entity, 'SOR_MASTER').' AS m',
                ),
                function ($join) {
                    $join->on('m.REFERENCE_NBR', '=', 'l.REFERENCE_NBR')
                        ->on('m.REFERENCE_DUPLICATE', '=', 'l.REFERENCE_DUPLICATE')
                        ->on('m.TRANS_TYPE', '=', 'l.TRANS_TYPE');
                },
            )
            ->where('m.TRANS_TYPE', 'DRSOR')
            ->whereNotIn('m.STATUS_FLAG', ['C', 'X'])
            ->where('l.LINE_STATUS', '<>', 'C')
            ->where('l.BACKORDER_QTY', '>', 0)
            ->orderBy('l.DELIVERY_DATE')
            ->get([
                'l.UNIQUE_ID', 'l.REFERENCE_NBR', 'l.LINE_NBR', 'l.STOCK_CODE', 'l.STOCK_LOCATION',
                'l.BACKORDER_QTY', 'l.ALLOCATED_QTY', 'l.DELIVERY_DATE', 'l.CUSTOMER_CODE',
                'l.DESCRIPTION', 'm.ORDER_DATE', 'm.DELIVERY_NAME', 'm.DELIVERY_NOTE_1',
            ]);
    }

    /**
     * @param  Collection<string, SkuMaster>  $skus
     * @param  Collection<string, string>  $categories
     * @param  Collection<string, Depot>  $depotsByLocation
     * @param  Collection<string, Collection<int, Depot>>  $depotsByEntity
     * @return array<string, mixed>|null
     */
    private function rowFor(
        string $entity,
        object $line,
        Collection $skus,
        Collection $categories,
        Collection $depotsByLocation,
        Collection $depotsByEntity,
        SyncResult $result,
    ): ?array {
        $code = trim((string) $line->STOCK_CODE);
        $quantity = (int) round((float) $line->BACKORDER_QTY);

        if ($code === '' || $quantity <= 0) {
            return null;
        }

        $route = $this->router->route($entity, $code);
        $supplying = $route->entityCode;

        if ($route->wasRouted) {
            $result->count('routed from a holding yard');
        }

        if ($route->isExcludedEntity()) {
            // A holding yard still selling stock made at the closing plant. Counted so the demand
            // can be re-sourced rather than quietly vanishing from the plan.
            $result->reject(
                'skipped (sourced from the closing plant)',
                sprintf('%s order %s line %s: [%s] is supplied by %s.',
                    $entity, $line->REFERENCE_NBR, $line->LINE_NBR, $code, $supplying),
            );

            return null;
        }

        $sku = $skus->get($supplying.'|'.$route->stockCode);

        if ($sku === null) {
            // Most of these are accessories a pipe order carries — rubber rings, sand bands, caps —
            // which no pipe machine makes. Naming the category keeps a real gap in the product
            // master visible instead of burying it under thousands of expected skips.
            $category = $categories->get($supplying.'|'.$route->stockCode)
                ?? $categories->get($entity.'|'.$code);

            if ($category !== null) {
                $result->count("skipped (category $category, not a planned pipe)");

                return null;
            }

            $result->reject(
                'skipped (not in Tencia stock master)',
                sprintf('%s order %s line %s: no product [%s] for %s.',
                    $entity, $line->REFERENCE_NBR, $line->LINE_NBR, $route->stockCode, $supplying),
            );

            return null;
        }

        $depot = $this->depotFor($supplying, $entity, $line, $depotsByLocation, $depotsByEntity);

        if ($depot === null) {
            $result->reject(
                'skipped (no plant for entity)',
                sprintf('%s order %s line %s: no plant maps to %s.',
                    $entity, $line->REFERENCE_NBR, $line->LINE_NBR, $supplying),
            );

            return null;
        }

        $ordered = $this->date($line->ORDER_DATE) ?? CarbonImmutable::now()->startOfDay();
        $delivery = $this->date($line->DELIVERY_DATE) ?? $ordered;

        $result->count('orders');

        return [
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
            'erp_order_reference' => (string) $line->REFERENCE_NBR,
            // UNIQUE_ID identifies the line for good, unlike a line number that can be renumbered.
            'erp_line_reference' => (string) $line->UNIQUE_ID,
            'customer_code' => trim((string) $line->CUSTOMER_CODE) ?: 'UNKNOWN',
            'customer_name' => trim((string) $line->DELIVERY_NAME) ?: 'Unknown customer',
            'delivery_address' => trim((string) $line->DELIVERY_NOTE_1) ?: 'Not stated',
            // BACKORDER_QTY is what is still to be made, so nothing of it is allocated yet.
            'ordered_quantity' => $quantity,
            'allocated_quantity' => 0,
            'requested_delivery_date' => $delivery->toDateString(),
            'ordered_at' => $ordered->toDateTimeString(),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * The plant that owns the line's stock location, which is the ERP's own answer to "whose work is
     * this". Routed holding-yard demand carries the yard's location, which no plant owns, so it
     * falls back to the supplying state.
     *
     * @param  Collection<string, Depot>  $depotsByLocation
     * @param  Collection<string, Collection<int, Depot>>  $depotsByEntity
     */
    private function depotFor(
        string $supplying,
        string $orderedBy,
        object $line,
        Collection $depotsByLocation,
        Collection $depotsByEntity,
    ): ?Depot {
        $location = trim((string) $line->STOCK_LOCATION);

        if ($supplying === $orderedBy && $location !== '') {
            $owned = $depotsByLocation->get($supplying.'|'.$location);

            if ($owned !== null) {
                return $owned;
            }
        }

        $candidates = $depotsByEntity->get($supplying);

        return $candidates?->first();
    }

    /**
     * Every stock code Tencia knows, with its category, so a code missing from the planner's product
     * master can be told apart from one that is deliberately not planned.
     *
     * @return Collection<string, string> Keys are "ENTITY|STOCK_CODE", values the category.
     */
    private function categoriesByEntityAndCode(): Collection
    {
        return $this->source->view('vwSTK_MASTER')
            ->get(['ENTITY', 'STOCK_CODE', 'STOCK_CATEGORY'])
            ->mapWithKeys(fn (object $row): array => [
                trim((string) $row->ENTITY).'|'.trim((string) $row->STOCK_CODE) => trim((string) $row->STOCK_CATEGORY),
            ]);
    }

    /**
     * @return Collection<string, SkuMaster> Keys are "ENTITY|STOCK_CODE".
     */
    private function skusByEntityAndCode(): Collection
    {
        return SkuMaster::query()
            ->whereNotNull('erp_entity_code')
            ->get(['id', 'sku_code', 'erp_entity_code', 'diameter_mm'])
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
