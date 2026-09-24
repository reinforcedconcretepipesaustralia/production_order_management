<?php

namespace App\Services\Sync;

use App\Models\Depot;
use App\Models\OrderAllocation;
use App\Models\SkuMaster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The outstanding order book — what the priority rule ranks on.
 *
 * Reads the future order book from dbo.vwSALES_ORDERS when a plan needs it. Invoiced pipe sales
 * are dbo.vwSALES and are not part of this read. Headers and lines in status C, S, or X are
 * excluded, and only quantities still to make are kept. Nothing is written to the local database.
 */
final class OrderSync
{
    private ?SyncResult $lastResult = null;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $openOrders = null;

    public function __construct(
        private TenciaSource $source,
        private HoldingYardRouter $router,
        private ProductSync $products,
    ) {}

    /**
     * Outstanding order lines for the plants, read from Tencia and not stored.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lines(): Collection
    {
        if ($this->openOrders !== null) {
            return $this->openOrders;
        }

        $result = new SyncResult;
        $depotsByLocation = $this->source->depotsByStockLocation();
        $depotsByEntity = Depot::query()->whereNotNull('erp_entity_code')->get()->groupBy('erp_entity_code');
        $skus = $this->products->byEntityAndCode();
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

        $this->lastResult = $result;

        return $this->openOrders = $this->withLocalAllocations(collect($rows));
    }

    public function result(): SyncResult
    {
        $this->lines();

        return $this->lastResult;
    }

    /**
     * Open order lines, exactly as the workbook's query defined "outstanding".
     *
     * @return Collection<int, object>
     */
    private function openLinesFor(string $entity): Collection
    {
        return $this->source->openSalesOrderLines($entity)->get();
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

        $sku = $skus->get($supplying.'|'.$route->stockCode)
            ?? $this->products->find($supplying, $route->stockCode);

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
        $delivery = $this->date($line->LINE_DELIVERY_DATE) ?? $ordered;

        $result->count('orders');

        return [
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
            'erp_entity_code' => $supplying,
            'stock_location' => trim((string) $line->STOCK_LOCATION),
            'erp_order_reference' => (string) $line->REFERENCE_NBR,
            // LINE_UNIQUE_ID identifies the line for good, unlike a line number that can be renumbered.
            // The per-state fallback still calls that key UNIQUE_ID.
            'erp_line_reference' => $this->firstFilled($line, 'LINE_UNIQUE_ID', 'UNIQUE_ID'),
            'customer_code' => trim((string) $line->CUSTOMER_CODE) ?: 'UNKNOWN',
            'customer_name' => $this->firstFilled($line, 'CUSTOMER_NAME', 'DELIVERY_NAME') ?: 'Unknown customer',
            'delivery_address' => $this->deliveryAddress($line),
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
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withLocalAllocations(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $allocated = OrderAllocation::query()
            ->whereIn('status', ['allocated', 'reserved'])
            ->whereNotNull('erp_line_reference')
            ->get(['erp_entity_code', 'erp_line_reference', 'quantity'])
            ->groupBy(fn (OrderAllocation $allocation): string => $allocation->erp_entity_code.'|'.$allocation->erp_line_reference)
            ->map(fn (Collection $group): int => (int) $group->sum('quantity'));

        return $rows->map(function (array $row) use ($allocated): array {
            $quantity = (int) $allocated->get($row['erp_entity_code'].'|'.$row['erp_line_reference'], 0);
            $row['allocated_quantity'] = $quantity;
            $row['status'] = match (true) {
                $quantity >= $row['ordered_quantity'] => 'allocated',
                $quantity > 0 => 'partially_allocated',
                default => 'open',
            };

            return $row;
        });
    }

    private function deliveryAddress(object $line): string
    {
        $note = $this->firstFilled($line, 'DELIVERY_NOTE_1');

        if ($note !== '') {
            return $note;
        }

        $parts = array_values(array_filter([
            $this->firstFilled($line, 'ADDRESS_1'),
            $this->firstFilled($line, 'ADDRESS_2'),
            $this->firstFilled($line, 'SUBURB'),
            $this->firstFilled($line, 'STATE'),
            $this->firstFilled($line, 'POSTCODE'),
        ], fn (string $part): bool => $part !== ''));

        return $parts === [] ? 'Not stated' : implode(', ', $parts);
    }

    private function firstFilled(object $line, string ...$names): string
    {
        foreach ($names as $name) {
            if (! isset($line->{$name})) {
                continue;
            }

            $value = trim((string) $line->{$name});

            if ($value !== '') {
                return $value;
            }
        }

        return '';
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
