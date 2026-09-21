<?php

namespace App\Services\Sync;

use App\Models\SkuMaster;
use App\Models\SkuWireRequirement;
use Illuminate\Support\Collection;

/**
 * Steel and concrete per pipe, from dbo.vwBOM_LINES. Replaces the workbook's 'BOM Tencia' tab and
 * its aggregated AJ:AQ steel columns.
 *
 * BILL_ORDER_QTY is per pipe, but its meaning depends on the component's unit of measure: wire is
 * in tonnes, the concrete mix in cubic metres, and labour and overhead recoveries share the tonnes
 * unit without being materials. Reading the quantity without the unit silently turns 0.0044 tonnes
 * of wire into 0.0044 kilograms.
 */
final class BomSync
{
    /** Wire arrives in tonnes per pipe; the planner orders and forecasts in kilograms. */
    private const KILOGRAMS_PER_TONNE = 1000;

    public function __construct(
        private TenciaSource $source,
        private HoldingYardRouter $router,
    ) {}

    public function sync(?string $onlyEntity = null): SyncResult
    {
        $result = new SyncResult;

        foreach ($this->entities($onlyEntity) as $entity) {
            // A holding yard makes nothing, so Tencia holds no bill of material for it.
            if ($this->router->isHoldingYard($entity)) {
                continue;
            }

            $this->syncEntity($entity, $result);
        }

        return $result;
    }

    private function syncEntity(string $entity, SyncResult $result): void
    {
        $skus = SkuMaster::query()
            ->where('erp_entity_code', $entity)
            ->get(['id', 'sku_code'])
            ->keyBy('sku_code');

        if ($skus->isEmpty()) {
            $result->reject(
                'skipped (no products for entity)',
                "$entity has no products yet — run sync:products first.",
            );

            return;
        }

        $rows = $this->source->view('vwBOM_LINES')
            ->where('ENTITY', $entity)
            ->orderBy('BILL_STOCK_CODE')
            ->get();

        /** @var Collection<string, array<string, array{wire_gauge_mm: float, kg_per_pipe: float}>> $wireByProduct */
        $wireByProduct = collect();
        $volumeByProduct = [];

        foreach ($rows as $row) {
            $billCode = trim((string) $row->BILL_STOCK_CODE);
            $componentCode = trim((string) $row->STOCK_CODE);
            $unit = strtoupper(trim((string) $row->UNIT_OF_MEASURE));
            $quantity = (float) $row->BILL_ORDER_QTY;

            if ($billCode === '' || $componentCode === '' || $quantity <= 0) {
                continue;
            }

            if (! $skus->has($billCode)) {
                // Components of things this plant does not plan, e.g. a precast product's bill.
                $result->count('skipped (product not planned)');

                continue;
            }

            if ($this->isWire($componentCode)) {
                $gauge = SkuWireRequirement::gaugeFromWireCode($componentCode);

                if ($gauge === null) {
                    $result->reject(
                        'skipped (wire gauge unreadable)',
                        "$entity $billCode: cannot read a gauge from wire code [$componentCode].",
                    );

                    continue;
                }

                if ($unit !== 'TONN') {
                    $result->reject(
                        'skipped (unexpected wire unit)',
                        "$entity $billCode $componentCode: expected TONN, found [$unit].",
                    );

                    continue;
                }

                $existing = $wireByProduct->get($billCode, []);
                // A gauge can appear on more than one line of the same bill; the pipe needs the sum.
                $existing[$componentCode] = [
                    'wire_gauge_mm' => $gauge,
                    'kg_per_pipe' => ($existing[$componentCode]['kg_per_pipe'] ?? 0)
                        + $quantity * self::KILOGRAMS_PER_TONNE,
                ];
                $wireByProduct->put($billCode, $existing);

                continue;
            }

            if ($unit === 'M3') {
                $volumeByProduct[$billCode] ??= ['volume' => 0, 'mix' => null];
                $volumeByProduct[$billCode]['volume'] += $quantity;
                // Mixes differ in cement content, so the code is kept alongside the volume. A pipe
                // batched from more than one mix keeps the largest, which is the one it is made of.
                $volumeByProduct[$billCode]['mix'] ??= $componentCode;
            }

            // Labour and overhead recoveries share the tonnes unit but are not materials.
        }

        $result->merge($this->writeWire($skus, $wireByProduct));
        $result->merge($this->writeVolumes($skus, $volumeByProduct));
    }

    /**
     * @param  Collection<string, SkuMaster>  $skus
     * @param  Collection<string, array<string, array{wire_gauge_mm: float, kg_per_pipe: float}>>  $wireByProduct
     */
    private function writeWire(Collection $skus, Collection $wireByProduct): SyncResult
    {
        $result = new SyncResult;

        foreach ($wireByProduct as $billCode => $gauges) {
            $sku = $skus->get($billCode);

            foreach ($gauges as $wireCode => $requirement) {
                SkuWireRequirement::query()->updateOrCreate(
                    ['sku_master_id' => $sku->id, 'wire_code' => $wireCode],
                    [
                        'wire_gauge_mm' => $requirement['wire_gauge_mm'],
                        'kg_per_pipe' => round($requirement['kg_per_pipe'], 3),
                    ],
                );

                $result->count('wire requirements');
            }

            // A gauge dropped from a design must not linger and keep being ordered.
            $removed = SkuWireRequirement::query()
                ->where('sku_master_id', $sku->id)
                ->whereNotIn('wire_code', array_keys($gauges))
                ->delete();

            $result->count('wire requirements removed', $removed);
            $result->count('products with a bill');
        }

        return $result;
    }

    /**
     * @param  Collection<string, SkuMaster>  $skus
     * @param  array<string, array{volume: float, mix: string|null}>  $volumeByProduct
     */
    private function writeVolumes(Collection $skus, array $volumeByProduct): SyncResult
    {
        $result = new SyncResult;

        foreach ($volumeByProduct as $billCode => $mix) {
            $skus->get($billCode)->forceFill([
                'concrete_volume_m3' => round($mix['volume'], 4),
                'concrete_mix_code' => $mix['mix'],
            ])->save();

            $result->count('concrete volumes');
        }

        return $result;
    }

    private function isWire(string $stockCode): bool
    {
        return str_starts_with(strtoupper($stockCode), 'WIRE');
    }

    /**
     * @return list<string>
     */
    private function entities(?string $onlyEntity): array
    {
        $entities = $this->source->plannedEntities();

        return $onlyEntity === null
            ? $entities
            : array_values(array_filter($entities, fn (string $entity): bool => $entity === $onlyEntity));
    }
}
