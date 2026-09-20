<?php

namespace App\Services\Sync;

use App\Models\Depot;
use App\Models\SkuMaster;
use Illuminate\Support\Collection;

/**
 * Products, from dbo.vwSTK_MASTER. Replaces the workbook's cached 'Stk Master' tab.
 *
 * Which stock categories count as pipes comes from depots.erp_stock_categories, never from a literal
 * here: copying one state's category list into another is exactly the bug that is live in the VIC
 * workbook today, where a UNION branch filters on NSW's categories.
 */
final class ProductSync
{
    public function __construct(private TenciaSource $source) {}

    public function sync(?string $onlyEntity = null): SyncResult
    {
        $result = new SyncResult;
        $categories = $this->pipeCategoriesByEntity();

        if ($categories->isEmpty()) {
            $result->reject(
                'skipped (no plant mapped)',
                'No plant has both an ERP entity code and stock categories set.',
            );

            return $result;
        }

        foreach ($categories as $entity => $entityCategories) {
            if ($onlyEntity !== null && $entity !== $onlyEntity) {
                continue;
            }

            $this->syncEntity((string) $entity, $entityCategories, $result);
        }

        return $result;
    }

    /**
     * @param  list<string>  $categories
     */
    private function syncEntity(string $entity, array $categories, SyncResult $result): void
    {
        $rows = $this->source->view('vwSTK_MASTER')
            ->where('ENTITY', $entity)
            ->whereIn('STOCK_CATEGORY', $categories)
            ->orderBy('STOCK_CODE')
            ->get();

        foreach ($rows as $row) {
            $code = trim((string) $row->STOCK_CODE);

            if ($code === '') {
                continue;
            }

            // Tencia's own flags, so a product retired in the ERP stops being planned here.
            $isActive = ! $this->flagged($row->OBSOLETE_STOCK) && ! $this->flagged($row->DELETE_STOCK);

            $sku = $this->claim($entity, $code);

            $sku->fill([
                'description' => trim((string) $row->STOCK_DESCRIPTION_1) ?: $code,
                'unit_weight_tonnes' => (float) $row->UNIT_WEIGHT,
                'is_active' => $isActive,
            ]);

            $diameter = HoldingYardRouter::diameterFromCode($code);

            if ($diameter !== null) {
                $sku->diameter_mm = $diameter;
            }

            $sku->save();

            $result->count($isActive ? 'products' : 'products (inactive in Tencia)');
        }

        if ($rows->isEmpty()) {
            $result->reject(
                'skipped (no products found)',
                sprintf('%s returned no rows for categories %s.', $entity, implode(', ', $categories)),
            );
        }
    }

    /**
     * Find this entity's product, adopting a row the workbook stopgap created before entities were
     * recorded. Adoption happens once: the row comes back with its entity set.
     */
    private function claim(string $entity, string $code): SkuMaster
    {
        $existing = SkuMaster::query()
            ->where('sku_code', $code)
            ->where(fn ($query) => $query->where('erp_entity_code', $entity)->orWhereNull('erp_entity_code'))
            ->orderByRaw('CASE WHEN erp_entity_code IS NULL THEN 1 ELSE 0 END')
            ->first();

        if ($existing !== null) {
            $existing->erp_entity_code = $entity;

            return $existing;
        }

        return new SkuMaster([
            'sku_code' => $code,
            'erp_entity_code' => $entity,
            // Superseded by sku_wire_requirements, but the columns are not nullable.
            'wire_code' => '',
            'wire_kg_per_pipe' => 0,
            'standard_run_rate_per_hour' => 0,
        ]);
    }

    /**
     * @return Collection<string, list<string>>
     */
    private function pipeCategoriesByEntity(): Collection
    {
        $byEntity = [];

        foreach (Depot::query()->whereNotNull('erp_entity_code')->get() as $depot) {
            $categories = $depot->erp_stock_categories ?? [];

            if ($categories === []) {
                continue;
            }

            $byEntity[$depot->erp_entity_code] = array_values(array_unique(
                array_merge($byEntity[$depot->erp_entity_code] ?? [], $categories),
            ));
        }

        return collect($byEntity)->reject(
            fn (array $categories, string $entity): bool => in_array($entity, TenciaSource::EXCLUDED_ENTITIES, true),
        );
    }

    private function flagged(mixed $value): bool
    {
        $normalised = strtoupper(trim((string) $value));

        return in_array($normalised, ['Y', 'YES', 'T', 'TRUE', '1'], true);
    }
}
