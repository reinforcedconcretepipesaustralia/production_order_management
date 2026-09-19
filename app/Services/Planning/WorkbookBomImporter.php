<?php

namespace App\Services\Planning;

use App\Models\Depot;
use App\Models\MachineAsset;
use App\Models\MachineSkuRunRate;
use App\Models\SkuMaster;
use App\Models\SkuWireRequirement;
use App\Support\Excel\BomLayout;
use App\Support\Excel\XlsxReader;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Loads products, run rates and steel-per-pipe out of a state planning workbook.
 *
 * The workbooks cache the Tencia data they pull — 'Stk Master' holds the product master and
 * 'BOM' holds the aggregated steel weight per wire gauge alongside each machine's rated speed.
 * That makes a full reference-data load possible with no database access at all, which matters
 * because the SQL host is only reachable from the corporate network.
 *
 * This is a stopgap that the Tencia sync will supersede: a workbook is only as current as its
 * last refresh, so the import records nothing that the sync cannot later correct.
 */
final class WorkbookBomImporter
{
    /**
     * @return array<string, int> Counts of what was written.
     */
    public function import(string $path, string $entityCode, bool $dryRun = false): array
    {
        $depots = Depot::query()
            ->with('machines')
            ->where('erp_entity_code', $entityCode)
            ->get();

        if ($depots->isEmpty()) {
            throw new RuntimeException("No plants are mapped to entity [{$entityCode}].");
        }

        $reader = new XlsxReader($path);
        $descriptions = $this->descriptionsFrom($reader);
        $machines = $this->machinesFor($depots);

        $counts = [
            'products' => 0,
            'run rates' => 0,
            'wire requirements' => 0,
            'skipped (no machine match)' => 0,
            'skipped (not current)' => 0,
        ];

        $layout = null;
        $headerRows = [];

        foreach ($reader->rows('BOM') as $rowNumber => $cells) {
            if ($layout === null) {
                $headerRows[$rowNumber] = $cells;

                if ($rowNumber >= 5) {
                    $layout = BomLayout::detect($headerRows);
                }

                continue;
            }

            if ($rowNumber < $layout->firstDataRow) {
                continue;
            }

            $skuCode = trim($cells[$layout->column('sku_code')] ?? '');

            if ($skuCode === '') {
                continue;
            }

            $status = strtolower(trim($cells[$layout->column('status')] ?? 'current'));

            // Obsolete products are left out so they cannot be planned.
            if ($status !== '' && $status !== 'current') {
                $counts['skipped (not current)']++;

                continue;
            }

            if ($dryRun) {
                $counts['products']++;

                continue;
            }

            $sku = $this->upsertSku($cells, $layout, $skuCode, $descriptions);
            $counts['products']++;

            $counts['wire requirements'] += $this->syncWireRequirements($sku, $cells, $layout);

            $machineName = trim($cells[$layout->column('machine')] ?? '');
            $machine = $this->matchMachine($machineName, $machines);

            if ($machine === null) {
                if ($machineName !== '') {
                    $counts['skipped (no machine match)']++;
                }

                continue;
            }

            $counts['run rates'] += $this->upsertRunRate($machine, $sku, $cells, $layout) ? 1 : 0;
        }

        if ($layout === null) {
            throw new RuntimeException('Could not read the BOM sheet header.');
        }

        return $counts;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, string>  $descriptions
     */
    private function upsertSku(array $cells, BomLayout $layout, string $skuCode, array $descriptions): SkuMaster
    {
        $diameter = $this->numeric($cells, $layout->column('diameter'));
        $adjusted = $this->numeric($cells, $layout->column('adjusted_speed'))
            ?? $this->numeric($cells, $layout->column('rated_speed'));

        $attributes = [
            'description' => $descriptions[$skuCode] ?? $this->composeDescription($skuCode, $diameter, $cells, $layout),
            'diameter_mm' => $diameter === null ? null : (int) round($diameter),
            'unit_weight_tonnes' => $this->numeric($cells, $layout->column('unit_weight')) ?? 0,
            'concrete_volume_m3' => $this->numeric($cells, $layout->column('concrete_volume')) ?? 0,
            'machine_preference' => trim($cells[$layout->column('machine')] ?? '') ?: null,
            'bom_reference' => trim($cells[$layout->column('mould_type')] ?? '') ?: null,
            'is_active' => true,
        ];

        // standard_run_rate_per_hour is not null, and is only a fallback for display; the
        // machine-specific rate is what the planner uses.
        if ($adjusted !== null && $adjusted > 0) {
            $attributes['standard_run_rate_per_hour'] = $adjusted;
        }

        $existing = SkuMaster::query()->where('sku_code', $skuCode)->first();

        if ($existing === null) {
            $attributes['standard_run_rate_per_hour'] ??= 0;
            // wire_code is superseded by sku_wire_requirements but the column is not nullable.
            $attributes['wire_code'] = '';
        }

        return SkuMaster::query()->updateOrCreate(['sku_code' => $skuCode], $attributes);
    }

    /**
     * @param  array<string, string>  $cells
     */
    private function upsertRunRate(MachineAsset $machine, SkuMaster $sku, array $cells, BomLayout $layout): bool
    {
        $rated = $this->numeric($cells, $layout->column('rated_speed'));

        if ($rated === null || $rated <= 0) {
            return false;
        }

        $adjustment = $this->numeric($cells, $layout->column('adjustment'));

        MachineSkuRunRate::query()->updateOrCreate(
            ['machine_asset_id' => $machine->id, 'sku_master_id' => $sku->id],
            [
                'rated_per_hour' => $rated,
                'adjustment_factor' => $adjustment !== null && $adjustment > 0 ? $adjustment : null,
                'is_active' => true,
            ],
        );

        return true;
    }

    /**
     * The BOM's steel columns are already aggregated kilograms per pipe per gauge, so they map
     * straight onto sku_wire_requirements. Gauges are turned back into the Tencia wire stock
     * codes the steel forecast orders against.
     *
     * @param  array<string, string>  $cells
     */
    private function syncWireRequirements(SkuMaster $sku, array $cells, BomLayout $layout): int
    {
        $written = 0;
        $seen = [];

        foreach ($layout->steelColumns as $gauge => $column) {
            $kilograms = $this->numeric($cells, $column);

            if ($kilograms === null || $kilograms <= 0) {
                continue;
            }

            $wireCode = $this->wireCodeFor($gauge);
            $seen[] = $wireCode;

            SkuWireRequirement::query()->updateOrCreate(
                ['sku_master_id' => $sku->id, 'wire_code' => $wireCode],
                ['wire_gauge_mm' => (float) $gauge, 'kg_per_pipe' => $kilograms],
            );

            $written++;
        }

        // A gauge dropped from a design must not linger and keep being ordered.
        SkuWireRequirement::query()
            ->where('sku_master_id', $sku->id)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('wire_code', $seen))
            ->delete();

        return $written;
    }

    /**
     * Gauges up to 6.23 mm are plain wire and above that deformed, matching the WIREPL and
     * WIREDE stock codes on the Steel Forecast tabs.
     */
    private function wireCodeFor(string $gauge): string
    {
        return ((float) $gauge <= 6.23 ? 'WIREPL' : 'WIREDE').$gauge;
    }

    /**
     * Machine names are inconsistently cased across the workbooks — 'BiDI 84' appears 250 times
     * and 'BiDi 84' once in the VIC BOM — and at the single-machine plants the column holds the
     * plant name instead. Matching is case-insensitive on either.
     *
     * @param  Collection<string, MachineAsset>  $machines
     */
    private function matchMachine(string $name, Collection $machines): ?MachineAsset
    {
        if ($name === '') {
            return null;
        }

        return $machines->get(strtolower(trim($name)));
    }

    /**
     * @param  Collection<int, Depot>  $depots
     * @return Collection<string, MachineAsset> Lower-cased machine name and plant name/code.
     */
    private function machinesFor(Collection $depots): Collection
    {
        $machines = collect();

        foreach ($depots as $depot) {
            foreach ($depot->machines->where('is_active', true) as $machine) {
                $machines->put(strtolower($machine->name), $machine);
            }

            // A plant that runs one machine is named directly in the BOM's machine column.
            $single = $depot->machines->where('is_active', true);

            if ($single->count() === 1) {
                $machines->put(strtolower($depot->name), $single->first());
                $machines->put(strtolower($depot->code), $single->first());
            }
        }

        return $machines;
    }

    /**
     * Product descriptions as Tencia holds them, cached on the workbook's 'Stk Master' tab.
     *
     * @return array<string, string>
     */
    private function descriptionsFrom(XlsxReader $reader): array
    {
        if (! array_key_exists('Stk Master', $reader->sheets())) {
            return [];
        }

        $descriptions = [];
        $codeColumn = null;
        $descriptionColumn = null;

        foreach ($reader->rows('Stk Master') as $cells) {
            if ($codeColumn === null) {
                foreach ($cells as $column => $value) {
                    $header = strtoupper(trim($value));

                    if ($header === 'STOCK_CODE') {
                        $codeColumn = $column;
                    }

                    if ($header === 'STOCK_DESCRIPTION_1') {
                        $descriptionColumn = $column;
                    }
                }

                continue;
            }

            $code = trim($cells[$codeColumn] ?? '');
            $description = trim($cells[$descriptionColumn ?? ''] ?? '');

            if ($code !== '' && $description !== '') {
                $descriptions[$code] = $description;
            }
        }

        return $descriptions;
    }

    /**
     * @param  array<string, string>  $cells
     */
    private function composeDescription(string $skuCode, ?float $diameter, array $cells, BomLayout $layout): string
    {
        $class = trim($cells[$layout->column('class')] ?? '');
        $joint = trim($cells[$layout->column('joint')] ?? '');

        $parts = array_filter([
            $diameter === null ? null : (int) round($diameter).'mm',
            $joint !== '' ? $joint : null,
            'pipe',
            $class !== '' ? "class {$class}" : null,
        ]);

        return $parts === [] ? $skuCode : ucfirst(implode(' ', $parts));
    }

    /**
     * @param  array<string, string>  $cells
     */
    private function numeric(array $cells, ?string $column): ?float
    {
        if ($column === null || ! isset($cells[$column]) || ! is_numeric($cells[$column])) {
            return null;
        }

        return (float) $cells[$column];
    }
}
