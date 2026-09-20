<?php

namespace App\Services\Planning;

use App\Models\Depot;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use App\Services\Sync\HoldingYardRouter;
use App\Services\Sync\TenciaSource;
use App\Support\Excel\XlsxReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Loads the national outstanding order extract — every entity in one file, with a delivery date
 * and a stock location per line.
 *
 * Closer to what the Tencia views will return than the per-state workbook tabs, and the better
 * stopgap until the SQL host is reachable: it carries the delivery date the priority rule ranks
 * on, and it names the entity rather than leaving it to be inferred.
 */
final class OrderBookImporter
{
    private const SHEET = 'Outstanding Order';

    public function __construct(private HoldingYardRouter $router) {}

    /**
     * @return array<string, int>
     */
    public function import(string $path, bool $replace = true): array
    {
        $reader = new XlsxReader($path);

        if (! array_key_exists(self::SHEET, $reader->sheets())) {
            throw new RuntimeException(sprintf(
                'No [%s] sheet in that file. Found: %s',
                self::SHEET,
                implode(', ', array_keys($reader->sheets())),
            ));
        }

        $depots = Depot::query()->with('machines')->orderBy('code')->get();
        $skus = SkuMaster::query()->get(['id', 'sku_code', 'diameter_mm', 'machine_preference'])->keyBy('sku_code');

        $counts = [
            'imported' => 0,
            'routed from a holding yard' => 0,
            'skipped (closing plant)' => 0,
            'skipped (unknown product)' => 0,
            'skipped (no plant for entity)' => 0,
        ];

        $columns = null;
        $rows = [];

        foreach ($reader->rows(self::SHEET) as $cells) {
            if ($columns === null) {
                $columns = $this->headerMap($cells);

                continue;
            }

            $entity = trim($cells[$columns['Entity'] ?? ''] ?? '');
            $code = trim($cells[$columns['Stock Code'] ?? ''] ?? '');
            $quantity = (int) round((float) ($cells[$columns['Outstanding Qty'] ?? ''] ?? 0));

            if ($entity === '' || $code === '' || $quantity <= 0) {
                continue;
            }

            if (in_array($entity, TenciaSource::EXCLUDED_ENTITIES, true)) {
                $counts['skipped (closing plant)']++;

                continue;
            }

            $sku = $skus->get($code);

            if ($sku === null) {
                $counts['skipped (unknown product)']++;

                continue;
            }

            // The extract names no per-line source, so the diameter rule is all this can offer; the
            // SQL sync routes on Tencia's own prefixed codes instead.
            $supplying = $this->router->supplyingEntity($entity, $sku);

            if ($supplying !== $entity) {
                $counts['routed from a holding yard']++;
            }

            $depot = $this->depotFor($depots, $supplying, $sku);

            if ($depot === null) {
                $counts['skipped (no plant for entity)']++;

                continue;
            }

            $rows[] = $this->rowFor($cells, $columns, $depot, $sku, $quantity, $counts['imported']);
            $counts['imported']++;
        }

        if ($replace) {
            SalesOrder::query()->delete();
        }

        foreach (array_chunk($this->uniqueByReference($rows), 500) as $chunk) {
            SalesOrder::query()->upsert($chunk, ['erp_order_reference', 'erp_line_reference']);
        }

        return $counts;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, string>  $columns
     * @return array<string, mixed>
     */
    private function rowFor(
        array $cells,
        array $columns,
        Depot $depot,
        SkuMaster $sku,
        int $quantity,
        int $index,
    ): array {
        $order = trim($cells[$columns['Sales Order'] ?? ''] ?? '');
        $line = trim($cells[$columns['Line ID'] ?? ''] ?? '');
        $delivery = XlsxReader::serialToDate($cells[$columns['Delivery Date'] ?? ''] ?? '');
        $ordered = XlsxReader::serialToDate($cells[$columns['Order Date'] ?? ''] ?? '');

        return [
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
            'erp_order_reference' => $order !== '' ? $order : 'EXTRACT-'.$index,
            'erp_line_reference' => $line !== '' ? $line : (string) $index,
            'customer_code' => trim($cells[$columns['Customer Code'] ?? ''] ?? '') ?: 'UNKNOWN',
            'customer_name' => trim($cells[$columns['Customer Name'] ?? ''] ?? '') ?: 'Unknown customer',
            'delivery_address' => trim($cells[$columns['Project Name'] ?? ''] ?? '') ?: 'Not stated',
            // Outstanding Qty is already net of what has shipped, so nothing is allocated here.
            'ordered_quantity' => $quantity,
            'allocated_quantity' => 0,
            'requested_delivery_date' => $delivery ?? $ordered ?? CarbonImmutable::now()->toDateString(),
            'ordered_at' => $ordered ?? CarbonImmutable::now()->toDateString(),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param  Collection<int, Depot>  $depots
     */
    private function depotFor(Collection $depots, string $entity, SkuMaster $sku): ?Depot
    {
        $candidates = $depots->where('erp_entity_code', $entity);

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // Several plants in the state: the BOM's machine column says which one makes it.
        $preference = strtolower(trim((string) $sku->machine_preference));

        if ($preference !== '') {
            $matched = $candidates->first(
                fn (Depot $depot): bool => strtolower($depot->name) === $preference
                    || strtolower($depot->code) === $preference
                    || $depot->machines->contains(fn ($machine): bool => strtolower($machine->name) === $preference),
            );

            if ($matched !== null) {
                return $matched;
            }
        }

        return $candidates->first();
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
     * @param  array<string, string>  $cells
     * @return array<string, string>
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
}
