<?php

namespace App\Services\Sync;

use App\Models\Depot;
use Illuminate\Support\Collection;

/**
 * Stock on hand per plant, split into prime, rework and quarantine.
 *
 * Reads dbo.vwSTOCK_ON_HAND. Which kind a location holds is configuration on the plant, so a
 * new yard is a data change. Rework can cover an order; quarantine cannot. Nothing is written
 * to the local database.
 */
final class StockSync
{
    private ?SyncResult $lastResult = null;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $positions = null;

    public function __construct(
        private TenciaSource $source,
        private ProductSync $products,
    ) {}

    /**
     * Stock positions, read from Tencia and not stored.
     *
     * @return Collection<int, array{depot_id: int, sku_master_id: int, stock_location: string, kind: string, on_hand: int}>
     */
    public function positions(): Collection
    {
        if ($this->positions !== null) {
            return $this->positions;
        }

        $result = new SyncResult;
        $rows = [];

        foreach ($this->source->plannedEntities() as $entity) {
            $depots = Depot::query()->where('erp_entity_code', $entity)->get();

            if ($depots->isEmpty()) {
                continue;
            }

            foreach ($this->source->stockOnHand($entity)->get() as $row) {
                $code = trim((string) ($row->{'Stock Code'} ?? $row->STOCK_CODE ?? ''));
                $location = trim((string) ($row->{'Location Code'} ?? $row->STOCK_LOCATION ?? ''));
                $sku = $this->products->find($entity, $code);

                if ($sku === null || $location === '') {
                    continue;
                }

                $depot = $depots->first(fn (Depot $candidate): bool => $candidate->stockKindFor($location) !== null);

                if ($depot === null) {
                    $result->count('skipped (location not a plant yard)');

                    continue;
                }

                $onHand = (float) ($row->{'Qty On Hand'} ?? $row->ON_HAND_QTY ?? 0);
                $quantity = max(0, (int) round($onHand));

                $rows[] = [
                    'depot_id' => $depot->id,
                    'sku_master_id' => $sku->id,
                    'stock_location' => $location,
                    'kind' => $depot->stockKindFor($location),
                    'on_hand' => $quantity,
                ];
                $result->count('stock positions');

                if ($onHand < 0) {
                    $result->count('locations with negative stock in Tencia');
                }
            }
        }

        $this->lastResult = $result;

        return $this->positions = collect($rows);
    }

    /**
     * @return Collection<int, array{prime: int, rework: int}>
     */
    public function forDepot(Depot $depot): Collection
    {
        return $this->positions()
            ->filter(fn (array $row): bool => $row['depot_id'] === $depot->id)
            ->groupBy('sku_master_id')
            ->map(fn (Collection $skuRows): array => [
                'prime' => (int) $skuRows->where('kind', 'prime')->sum('on_hand'),
                'rework' => (int) $skuRows->where('kind', 'rework')->sum('on_hand'),
            ]);
    }

    public function result(): SyncResult
    {
        $this->positions();

        return $this->lastResult ?? new SyncResult;
    }
}
