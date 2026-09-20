<?php

namespace App\Services\Sync;

use App\Models\Depot;
use App\Models\InventorySoh;
use App\Models\SkuMaster;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stock on hand per plant, split into prime, rework and quarantine.
 *
 * Reads STK_LOCN_MASTER in each state's own database. The consolidated vwSTOCK_ON_HAND cannot serve
 * this: it holds only the rings/sandbands/caps and seconds categories — 142 rows nationally, with no
 * pipes in it at all.
 *
 * Which kind a location holds is configuration on the plant, so a new yard is a data change. The
 * distinction matters because rework is real stock that can cover an order, while quarantine cannot.
 */
final class StockSync
{
    public function __construct(private TenciaSource $source) {}

    public function sync(): SyncResult
    {
        $result = new SyncResult;
        $skus = $this->skusByEntityAndCode();
        $countedAt = now();

        foreach ($this->source->plannedEntities() as $entity) {
            $depots = Depot::query()->where('erp_entity_code', $entity)->get();

            if ($depots->isEmpty()) {
                // A holding yard has no plant, and holds no plant's stock.
                continue;
            }

            $this->syncEntity($entity, $depots, $skus, $countedAt, $result);
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
        Carbon $countedAt,
        SyncResult $result,
    ): void {
        $rows = $this->source->stateTable($entity, 'STK_LOCN_MASTER')
            ->orderBy('STOCK_CODE')
            ->get(['STOCK_CODE', 'STOCK_LOCATION', 'ON_HAND_QTY', 'ALLOCATED_QTY', 'BACKORDER_QTY']);

        /** @var array<string, array{depot_id: int, sku_master_id: int, prime: int, rework: int, quarantine: int}> $totals */
        $totals = [];

        foreach ($rows as $row) {
            $code = trim((string) $row->STOCK_CODE);
            $location = trim((string) $row->STOCK_LOCATION);
            $sku = $skus->get($entity.'|'.$code);

            if ($sku === null || $location === '') {
                continue;
            }

            $depot = $depots->first(fn (Depot $candidate): bool => $candidate->stockKindFor($location) !== null);

            if ($depot === null) {
                // Precast and transit yards are not pipe-plant stock.
                $result->count('skipped (location not a plant yard)');

                continue;
            }

            $kind = $depot->stockKindFor($location);
            // Quantities go negative in Tencia and that is not an error, but a negative cannot be
            // held in an unsigned column, so it is floored at nothing on hand.
            $quantity = max(0, (int) round((float) $row->ON_HAND_QTY));

            $key = $depot->id.'|'.$sku->id;
            $totals[$key] ??= [
                'depot_id' => $depot->id,
                'sku_master_id' => $sku->id,
                'prime' => 0,
                'rework' => 0,
                'quarantine' => 0,
            ];
            $totals[$key][$kind] += $quantity;

            if ($row->ON_HAND_QTY < 0) {
                $result->count('locations with negative stock in Tencia');
            }
        }

        foreach ($totals as $total) {
            InventorySoh::query()->updateOrCreate(
                [
                    'depot_id' => $total['depot_id'],
                    'sku_master_id' => $total['sku_master_id'],
                    'bin_location' => 'TENCIA',
                ],
                [
                    'prime_quantity' => $total['prime'],
                    'rework_quantity' => $total['rework'],
                    'quarantine_quantity' => $total['quarantine'],
                    'counted_at' => $countedAt,
                ],
            );

            $result->count('stock positions');
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
}
