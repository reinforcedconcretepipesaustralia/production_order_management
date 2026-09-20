<?php

namespace App\Services\Planning;

use App\Models\Depot;
use App\Models\Shift;
use App\Models\SkuMaster;
use App\Services\Scheduling\ShiftCapacityCalculator;
use App\Support\Excel\ProdPlanLayout;
use App\Support\Excel\XlsxReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Recomputes each planned line of a state's 'Prod Plan' sheet with this app's capacity model and
 * reports where the two disagree.
 *
 * A row reconciles when the app's planned pipes equal the workbook's, given the same SKU, the
 * same share of shift, and the OEE settings and run rate configured for that plant and shift.
 * Anything that cannot be compared — an unknown SKU, an unmapped plant, no run rate on file — is
 * reported as skipped rather than quietly counted as a match.
 */
final class WorkbookReconciler
{
    /** Rows scanned for the header block before giving up. */
    private const HEADER_SCAN_ROWS = 12;

    /** Restricts plant matching when the sheet does not name one. */
    private ?string $entityCode = null;

    public function __construct(
        private readonly ShiftCapacityCalculator $capacity,
        private readonly OeeSettingResolver $oeeSettings,
        private readonly RunRateResolver $runRates,
    ) {}

    public function reconcile(
        string $path,
        string $sheet = 'Prod  Plan',
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        int $tolerance = 0,
        ?string $entityCode = null,
    ): ReconciliationReport {
        $this->entityCode = $entityCode;

        $reader = new XlsxReader($path);

        // The layout is detected from a first short pass over the header block, so no planned row
        // can be mistaken for a header. Reading a dozen rows twice costs nothing next to
        // streaming a sheet of several thousand.
        $layout = ProdPlanLayout::tryDetect(iterator_to_array($reader->rows($sheet, self::HEADER_SCAN_ROWS)));

        if ($layout === null) {
            throw new RuntimeException(
                "Could not find the Prod Plan header on sheet [{$sheet}] within the first ".self::HEADER_SCAN_ROWS.' rows.',
            );
        }

        $rowsRead = 0;
        $rowsCompared = 0;
        $matched = 0;
        $variances = [];
        $skipped = [];

        foreach ($reader->rows($sheet) as $rowNumber => $cells) {
            if ($rowNumber < $layout->firstDataRow) {
                continue;
            }

            // The sheets name the plant and shift once per block and leave column E blank on the
            // continuation rows, so the last one seen carries forward the way a reader would
            // take it. Without this a blank cell matches nothing, or matches every shift.
            $scope = trim($cells['E'] ?? '');

            if ($scope !== '') {
                $lastScope = $scope;
            }

            $row = $this->parseRow($cells, $layout, $lastScope ?? '');

            if ($row === null) {
                continue;
            }

            if ($from !== null && $row['date']->lessThan($from)) {
                continue;
            }

            if ($to !== null && $row['date']->greaterThan($to)) {
                continue;
            }

            $rowsRead++;

            $comparison = $this->compare($row);

            if ($comparison['skipped'] !== null) {
                $skipped[$comparison['skipped']] = ($skipped[$comparison['skipped']] ?? 0) + 1;

                continue;
            }

            $rowsCompared++;
            $difference = $comparison['app'] - $row['planned_pipes'];

            if (abs($difference) <= $tolerance) {
                $matched++;

                continue;
            }

            $variances[] = [
                'row' => $rowNumber,
                'date' => $row['date']->format('D d M Y'),
                'scope' => $row['scope'],
                'sku' => $row['sku'],
                'workbook' => $row['planned_pipes'],
                'app' => $comparison['app'],
                'difference' => $difference,
                'explanation' => $comparison['explanation'],
            ];
        }

        return new ReconciliationReport(
            layoutSummary: $this->describeLayout($layout),
            rowsRead: $rowsRead,
            rowsCompared: $rowsCompared,
            matched: $matched,
            variances: $variances,
            skipped: $skipped,
        );
    }

    /**
     * @param  array<string, string>  $cells
     * @return array{date: CarbonImmutable, sku: string, scope: string, planned_pipes: int, shift_fraction: float, run_rate: float|null}|null
     */
    private function parseRow(array $cells, ProdPlanLayout $layout, string $scope): ?array
    {
        $skuColumn = $layout->column('sku');
        $dateColumn = $layout->column('date');
        $plannedColumn = $layout->column('planned_pipes');

        $sku = trim($cells[$skuColumn] ?? '');
        $planned = $cells[$plannedColumn] ?? '';

        // Only rows with a product and a planned quantity are part of the plan; the sheets carry
        // thousands of pre-formatted empty rows, and a row planning zero pipes is not a run.
        if ($sku === '' || ! is_numeric($planned) || (float) $planned <= 0) {
            return null;
        }

        $date = XlsxReader::serialToDate($cells[$dateColumn] ?? '');

        if ($date === null) {
            return null;
        }

        $fractionColumn = $layout->column('shift_fraction');
        $runRateColumn = $layout->column('run_rate');

        return [
            'date' => CarbonImmutable::parse($date),
            'sku' => $sku,
            // Column E carries plant, shift, or both depending on the state.
            'scope' => $scope,
            'planned_pipes' => (int) round((float) $planned),
            'shift_fraction' => $this->fractionFrom($cells[$fractionColumn] ?? null),
            'run_rate' => isset($cells[$runRateColumn]) && is_numeric($cells[$runRateColumn])
                ? (float) $cells[$runRateColumn]
                : null,
        ];
    }

    /**
     * @param  array{date: CarbonImmutable, sku: string, scope: string, planned_pipes: int, shift_fraction: float, run_rate: float|null}  $row
     * @return array{app: int, skipped: string|null, explanation: string}
     */
    private function compare(array $row): array
    {
        // Scoped to the workbook's own entity: the same stock code is a different product, with
        // different rated speeds, in another state.
        $sku = SkuMaster::query()
            ->where('sku_code', $row['sku'])
            ->when($this->entityCode !== null, fn ($query) => $query->where('erp_entity_code', $this->entityCode))
            ->first();

        if ($sku === null) {
            return $this->skip("{$row['sku']} is not in sku_master — import the BOM or sync products");
        }

        [$depot, $shifts] = $this->resolveScope($row['scope'], $row['date']);

        if ($depot === null) {
            return $this->skip("Could not map '{$row['scope']}' to a plant");
        }

        if ($shifts->isEmpty()) {
            return $this->skip("No shift matched '{$row['scope']}' at {$depot->code}");
        }

        $machine = $depot->machines->where('is_active', true)->first();

        if ($machine === null) {
            return $this->skip("No active machine at {$depot->code}");
        }

        $runRate = $this->runRates->resolve($machine, $sku);

        if ($runRate === null) {
            return $this->skip("No run rate for {$row['sku']} on any {$depot->code} machine");
        }

        // The workbook's own rated speed is preferred when present, so a capacity difference is
        // never just a difference in the rate each side happens to hold.
        $ratePerHour = $row['run_rate'] ?? $runRate->ratePerHour;

        // A row naming only a plant is planned against the plant's whole day, because that
        // workbook has no shift dimension to split it by. Summing the plant's shifts compares
        // like with like: NSW plans one Somersby row against a combined 14.75 effective hours,
        // which is exactly what its day and afternoon shifts add up to here.
        $effectiveHours = '0';
        $codes = [];

        foreach ($shifts as $shift) {
            $result = $this->capacity->calculate(
                shift: $shift,
                sku: $sku,
                oeeSetting: $this->oeeSettings->resolve($depot->id, $shift->id, $machine->id, $row['date']),
                runRatePerHour: $ratePerHour,
                durationMinutes: $shift->durationMinutesForWeekday($row['date']->dayOfWeek),
                shiftFraction: $row['shift_fraction'],
            );

            $effectiveHours = bcadd($effectiveHours, $result->effectiveHours, 6);
            $codes[] = $shift->code;
        }

        // Rounding happens once, on the combined figure. Rounding each shift up and then adding
        // would gain a pipe per shift — the workbook rounds the day's total.
        $exact = bcmul(
            bcmul($effectiveHours, number_format((float) $ratePerHour, 3, '.', ''), 8),
            number_format($row['shift_fraction'], 4, '.', ''),
            8,
        );
        $truncated = bcadd($exact, '0', 0);
        $plannedPipes = (int) (bccomp($exact, $truncated, 8) > 0 ? bcadd($truncated, '1', 0) : $truncated);

        return [
            'app' => $plannedPipes,
            'skipped' => null,
            'explanation' => sprintf(
                '%s at %s/hr x %s of shift, %s effective hr',
                implode('+', $codes),
                rtrim(rtrim((string) $ratePerHour, '0'), '.'),
                rtrim(rtrim(number_format($row['shift_fraction'], 4, '.', ''), '0'), '.'),
                rtrim(rtrim($effectiveHours, '0'), '.'),
            ),
        ];
    }

    /**
     * Column E is written differently in each state: 'Melton - AM' carries plant and shift,
     * 'Somersby' only a plant, 'Day shift' only a shift. Matching works on whatever the cell
     * does contain, and returns every shift the row should be compared against — one when the
     * cell names a shift, all of the plant's when it does not.
     *
     * @return array{0: Depot|null, 1: Collection<int, Shift>}
     */
    private function resolveScope(string $scope, CarbonImmutable $date): array
    {
        $normalised = strtolower($scope);
        $depots = Depot::query()
            ->with(['shifts.patterns', 'machines'])
            ->when($this->entityCode !== null, fn ($query) => $query->where('erp_entity_code', $this->entityCode))
            ->get();

        $depot = $depots->first(
            fn (Depot $candidate): bool => str_contains($normalised, strtolower($candidate->name))
                || str_contains($normalised, strtolower($candidate->code)),
        );

        $namesAfternoon = str_contains($normalised, 'aft') || str_contains($normalised, 'pm');
        $namesDay = str_contains($normalised, 'day') || str_contains($normalised, 'am');
        $shiftCode = $namesAfternoon ? 'AFT' : 'DAY';

        // A shift-only label such as 'Day shift' means the workbook covers one plant. Pass
        // --entity to say which, or fall back when only one plant could match.
        if ($depot === null) {
            $candidates = $depots->filter(
                fn (Depot $candidate): bool => $candidate->shifts
                    ->where('is_active', true)
                    ->contains('code', $shiftCode),
            );

            $depot = $candidates->count() === 1 ? $candidates->first() : null;
        }

        if ($depot === null) {
            return [null, collect()];
        }

        $active = $depot->shifts->where('is_active', true);

        if (! $namesAfternoon && ! $namesDay) {
            return [$depot, $active->values()];
        }

        $shift = $active->firstWhere('code', $shiftCode);

        return [$depot, $shift === null ? collect() : collect([$shift])];
    }

    private function fractionFrom(?string $value): float
    {
        if ($value === null || ! is_numeric($value)) {
            return 1.0;
        }

        $fraction = (float) $value;

        if ($fraction <= 0.0) {
            return 1.0;
        }

        // A value a little above one means an over-full shift, not a percentage: the Yatala
        // workbook records 1.11 for a day of overtime. Only values well above one are whole
        // percentages, and those are scaled down.
        return $fraction > 2.0 ? min(2.0, $fraction / 100) : $fraction;
    }

    /**
     * @return array{app: int, skipped: string, explanation: string}
     */
    private function skip(string $reason): array
    {
        return ['app' => 0, 'skipped' => $reason, 'explanation' => ''];
    }

    private function describeLayout(ProdPlanLayout $layout): string
    {
        $fields = collect($layout->columns)
            ->map(fn (string $column, string $field): string => "{$field}={$column}")
            ->implode(' ');

        $steel = $layout->steelColumns === []
            ? 'no steel block'
            : 'steel '.implode(',', $layout->steelColumns);

        return "{$fields} · {$steel} · data from row {$layout->firstDataRow}";
    }
}
