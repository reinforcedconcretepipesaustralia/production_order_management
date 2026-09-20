<?php

namespace App\Services\Planning;

use App\Models\Depot;
use App\Models\InventorySoh;
use App\Models\SalesHistoryMonthly;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use App\Support\Excel\XlsxReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Loads the order book, stock on hand and monthly sales from a 'Pipes to Manufacture' workbook.
 *
 * The same stopgap as the BOM import, for the same reason: the SQL host is only reachable from
 * the corporate network, and a planner cannot see a plan without demand to plan against. The
 * Tencia sync supersedes all of it.
 *
 * Monthly sales come from the 'Pipes to manufacture' tab rather than the 16,000-row sales
 * extract, because that tab already holds twelve months of eaches per product with the month
 * dates in its header — the aggregation this app needs, already done.
 */
final class WorkbookDemandImporter
{
    /**
     * @return array<string, int>
     */
    public function import(string $path, string $entityCode, bool $replace = true): array
    {
        $depots = Depot::query()
            ->where('erp_entity_code', $entityCode)
            ->orderBy('code')
            ->get();

        if ($depots->isEmpty()) {
            throw new RuntimeException("No plants are mapped to entity [{$entityCode}].");
        }

        $reader = new XlsxReader($path);
        // Scoped to the entity: the same stock code is a different product in another state.
        $skus = SkuMaster::query()
            ->where('erp_entity_code', $entityCode)
            ->pluck('id', 'sku_code');

        if ($replace) {
            $this->clear($depots);
        }

        return [
            'orders' => $this->importOrders($reader, $depots, $skus),
            'stock rows' => $this->importStock($reader, $depots, $skus),
            'sales months' => $this->importSalesHistory($reader, $depots, $skus),
        ];
    }

    /**
     * @param  Collection<int, Depot>  $depots
     */
    private function clear(Collection $depots): void
    {
        $depotIds = $depots->pluck('id');

        SalesOrder::query()->whereIn('depot_id', $depotIds)->delete();
        InventorySoh::query()->whereIn('depot_id', $depotIds)->delete();
        SalesHistoryMonthly::query()->whereIn('depot_id', $depotIds)->delete();
    }

    /**
     * @param  Collection<int, Depot>  $depots
     * @param  Collection<string, int>  $skus
     */
    private function importOrders(XlsxReader $reader, Collection $depots, Collection $skus): int
    {
        $sheet = $this->sheetNamed($reader, ['Outstanding Orders (OO)', 'Outstanding Orders']);

        if ($sheet === null) {
            return 0;
        }

        $columns = null;
        $imported = 0;
        $rows = [];

        foreach ($reader->rows($sheet) as $cells) {
            if ($columns === null) {
                $columns = $this->headerMap($cells);

                continue;
            }

            $code = trim($cells[$columns['STOCK_CODE'] ?? ''] ?? '');
            $quantity = (int) round((float) ($cells[$columns['OUTSTANDING_QTY'] ?? ''] ?? 0));

            if ($code === '' || $quantity <= 0 || ! $skus->has($code)) {
                continue;
            }

            $due = XlsxReader::serialToDate($cells[$columns['DELIVERY_DATE'] ?? ''] ?? '')
                ?? XlsxReader::serialToDate($cells[$columns['Due Date'] ?? ''] ?? '');

            $reference = trim($cells[$columns['REFERENCE_NBR'] ?? ''] ?? '');
            $line = trim($cells[$columns['LINE_NBR'] ?? ''] ?? '');

            $rows[] = [
                'depot_id' => $this->depotFor($depots, $code)->id,
                'sku_master_id' => $skus[$code],
                'erp_order_reference' => $reference !== '' ? $reference : 'WB-'.$imported,
                'erp_line_reference' => $line !== '' ? $line : (string) $imported,
                'customer_code' => trim($cells[$columns['CUSTOMER_CODE'] ?? ''] ?? '') ?: 'UNKNOWN',
                'customer_name' => trim($cells[$columns['DRS_MASTER.CUSTOMER_NAME'] ?? ''] ?? '') ?: 'Unknown customer',
                'delivery_address' => trim($cells[$columns['PROJECT_NAME'] ?? ''] ?? '') ?: 'Not stated',
                'ordered_quantity' => $quantity,
                'allocated_quantity' => 0,
                'requested_delivery_date' => $due ?? CarbonImmutable::now()->toDateString(),
                'ordered_at' => XlsxReader::serialToDate($cells[$columns['SOR_MASTER.ORDER_DATE'] ?? ''] ?? '')
                    ?? CarbonImmutable::now()->toDateString(),
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $imported++;
        }

        // The workbook can repeat an order line, so collapse on the natural key the table uses.
        foreach (array_chunk($this->uniqueByReference($rows), 500) as $chunk) {
            SalesOrder::query()->upsert($chunk, ['erp_order_reference', 'erp_line_reference']);
        }

        return $imported;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function uniqueByReference(array $rows): array
    {
        $seen = [];

        foreach ($rows as $row) {
            $seen[$row['erp_order_reference'].'|'.$row['erp_line_reference']] = $row;
        }

        return array_values($seen);
    }

    /**
     * @param  Collection<int, Depot>  $depots
     * @param  Collection<string, int>  $skus
     */
    private function importStock(XlsxReader $reader, Collection $depots, Collection $skus): int
    {
        $sheet = $this->sheetNamed($reader, ['Stock OH', 'In Stock']);

        if ($sheet === null) {
            return 0;
        }

        $columns = null;
        $totals = [];

        foreach ($reader->rows($sheet) as $cells) {
            if ($columns === null) {
                $columns = $this->headerMap($cells);

                continue;
            }

            $code = trim($cells[$columns['STOCK_CODE'] ?? ''] ?? '');
            $quantity = (int) round((float) ($cells[$columns['STK_LOCN_MASTER.ON_HAND_QTY'] ?? ''] ?? 0));

            if ($code === '' || $quantity === 0 || ! $skus->has($code)) {
                continue;
            }

            $location = trim($cells[$columns['STK_LOCN_MASTER.STOCK_LOCATION'] ?? ''] ?? '') ?: 'MAIN';
            $depot = $this->depotForLocation($depots, $location) ?? $this->depotFor($depots, $code);
            $isRework = strtolower(trim($cells[$columns['Good/Rework'] ?? ''] ?? '')) === 'rework'
                || in_array($location, $depot->rework_stock_locations ?? [], true);

            $key = $depot->id.'|'.$skus[$code].'|'.$location;
            $totals[$key] ??= [
                'depot_id' => $depot->id,
                'sku_master_id' => $skus[$code],
                'bin_location' => $location,
                'prime_quantity' => 0,
                'rework_quantity' => 0,
                'quarantine_quantity' => 0,
                'counted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $totals[$key][$isRework ? 'rework_quantity' : 'prime_quantity'] += max(0, $quantity);
        }

        foreach (array_chunk(array_values($totals), 500) as $chunk) {
            InventorySoh::query()->upsert($chunk, ['depot_id', 'sku_master_id', 'bin_location']);
        }

        return count($totals);
    }

    /**
     * Twelve months of eaches per product, read straight off the 'Pipes to manufacture' tab
     * where the month dates sit in the header above each column.
     *
     * @param  Collection<int, Depot>  $depots
     * @param  Collection<string, int>  $skus
     */
    private function importSalesHistory(XlsxReader $reader, Collection $depots, Collection $skus): int
    {
        $sheet = $this->sheetNamed($reader, ['Pipes to manufacture', 'Pipes to Manufacture']);

        if ($sheet === null) {
            return 0;
        }

        $months = [];
        $rows = [];
        $inData = false;

        foreach ($reader->rows($sheet) as $cells) {
            // The month serials sit in the header block, on the row above the 'Product Code'
            // label that marks where the products start. Anchoring on that label is steadier
            // than counting month columns, which varies between the files.
            if (! $inData) {
                foreach ($cells as $column => $value) {
                    if (! is_numeric($value)) {
                        continue;
                    }

                    $date = XlsxReader::serialToDate($value);

                    if ($date !== null && str_ends_with($date, '-01')) {
                        $months[$column] = $date;
                    }
                }

                if (strcasecmp(trim($cells['A'] ?? ''), 'Product Code') === 0 || count($months) >= 6) {
                    $inData = true;
                }

                continue;
            }

            $code = trim($cells['A'] ?? '');

            if ($code === '' || ! $skus->has($code)) {
                continue;
            }

            $depotId = $this->depotFor($depots, $code)->id;

            foreach ($months as $column => $month) {
                $quantity = (int) round((float) ($cells[$column] ?? 0));

                if ($quantity === 0) {
                    continue;
                }

                $rows[] = [
                    'depot_id' => $depotId,
                    'sku_master_id' => $skus[$code],
                    'month' => $month,
                    'quantity_ea' => $quantity,
                    'tonnes' => 0,
                    'net_value_ex_tax' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            SalesHistoryMonthly::query()->upsert($chunk, ['depot_id', 'sku_master_id', 'month']);
        }

        return count($rows);
    }

    /**
     * Which plant a product belongs to.
     *
     * Where a state runs more than one plant they share a yard, so stock cannot be attributed by
     * location. The BOM's machine column is the authority, and it is already on the SKU.
     *
     * @param  Collection<int, Depot>  $depots
     */
    private function depotFor(Collection $depots, string $skuCode): Depot
    {
        static $cache = [];

        // Every depot passed here belongs to one entity, and the same stock code means a different
        // product in another state, so the entity is part of the key.
        $entityCode = (string) $depots->first()?->erp_entity_code;
        $skuCode = $entityCode.'|'.$skuCode;

        if (isset($cache[$skuCode])) {
            return $cache[$skuCode];
        }

        $preference = SkuMaster::query()
            ->where('sku_code', explode('|', $skuCode, 2)[1])
            ->where('erp_entity_code', $entityCode)
            ->value('machine_preference');

        if ($preference !== null) {
            $needle = strtolower(trim($preference));

            $matched = $depots->first(
                fn (Depot $depot): bool => strtolower($depot->name) === $needle
                    || strtolower($depot->code) === $needle
                    || $depot->machines->contains(fn ($machine): bool => strtolower($machine->name) === $needle),
            );

            if ($matched !== null) {
                return $cache[$skuCode] = $matched;
            }
        }

        return $cache[$skuCode] = $depots->first();
    }

    /**
     * @param  Collection<int, Depot>  $depots
     */
    private function depotForLocation(Collection $depots, string $location): ?Depot
    {
        return $depots->first(fn (Depot $depot): bool => in_array($location, $depot->prime_stock_locations ?? [], true)
            || in_array($location, $depot->rework_stock_locations ?? [], true));
    }

    /**
     * @param  array<string, string>  $cells
     * @return array<string, string> Header text to column letter.
     */
    private function headerMap(array $cells): array
    {
        $map = [];

        foreach ($cells as $column => $value) {
            $header = trim($value);

            if ($header !== '') {
                $map[$header] ??= $column;
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function sheetNamed(XlsxReader $reader, array $candidates): ?string
    {
        $sheets = array_keys($reader->sheets());

        foreach ($candidates as $candidate) {
            foreach ($sheets as $sheet) {
                if (strcasecmp(trim($sheet), $candidate) === 0) {
                    return $sheet;
                }
            }
        }

        return null;
    }
}
