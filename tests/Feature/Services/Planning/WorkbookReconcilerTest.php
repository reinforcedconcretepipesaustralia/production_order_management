<?php

namespace Tests\Feature\Services\Planning;

use App\Models\Depot;
use App\Models\DepotShiftPattern;
use App\Models\MachineAsset;
use App\Models\MachineSkuRunRate;
use App\Models\OeeSetting;
use App\Models\Shift;
use App\Models\SkuMaster;
use App\Services\Planning\ReconciliationReport;
use App\Services\Planning\WorkbookReconciler;
use App\Support\Excel\XlsxReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\WorkbookFixture;
use Tests\TestCase;

/**
 * The reconciler is what earns the planners' trust, so its behaviour on the awkward parts of the
 * real workbooks is pinned down here: a shift named once per block, a plant-only row covering a
 * whole day, and a share of shift above one.
 */
class WorkbookReconcilerTest extends TestCase
{
    use RefreshDatabase;

    /** Tuesday 1 September 2026, serial 46266. */
    private const TUESDAY = 46266;

    public function test_reconciles_a_matching_row(): void
    {
        $this->melton();
        $path = $this->workbook([
            13 => ['A' => self::TUESDAY, 'E' => 'Melton - AM', 'F' => 'R0450-4', 'P' => 1, 'S' => 173, 'AE' => 32],
        ]);

        $report = $this->reconcile($path);

        $this->assertSame(1, $report->rowsCompared);
        $this->assertSame(1, $report->matched);
        $this->assertTrue($report->isReconciled());
    }

    public function test_reports_a_variance_with_its_reasoning(): void
    {
        $this->melton();
        // The workbook claims 200 where 9 effective hours at 60% availability gives 173.
        $path = $this->workbook([
            13 => ['A' => self::TUESDAY, 'E' => 'Melton - AM', 'F' => 'R0450-4', 'P' => 1, 'S' => 200, 'AE' => 32],
        ]);

        $report = $this->reconcile($path);

        $this->assertFalse($report->isReconciled());
        $this->assertCount(1, $report->variances);
        $this->assertSame(200, $report->variances[0]['workbook']);
        $this->assertSame(173, $report->variances[0]['app']);
        $this->assertSame(-27, $report->variances[0]['difference']);
        $this->assertStringContainsString('32/hr', $report->variances[0]['explanation']);
    }

    public function test_detects_the_layout_from_header_text_not_fixed_columns(): void
    {
        $this->melton();

        // Yatala's steel block sits one column across from VIC's, and its planned quantity with
        // it. Detection has to follow the headers.
        $path = $this->workbook(
            dataRows: [
                13 => ['A' => self::TUESDAY, 'E' => 'Melton - AM', 'F' => 'R0450-4', 'P' => 1, 'T' => 173, 'AF' => 32],
            ],
            headerOverrides: [
                2 => [
                    'A' => 'Manufacture Date', 'F' => 'PIPE', 'P' => '% OF SHIFT',
                    'T' => 'Planned Pipes (ea)', 'AC' => "Planned T's ", 'AF' => 'Run rate at 100%',
                    'AP' => '3.97', 'AQ' => '4.95', 'AX' => 'TOTAL',
                ],
            ],
        );

        $report = $this->reconcile($path);

        $this->assertStringContainsString('planned_pipes=T', $report->layoutSummary);
        $this->assertStringContainsString('steel AP,AQ', $report->layoutSummary);
        $this->assertSame(1, $report->matched);
    }

    public function test_a_blank_shift_cell_carries_the_previous_one_forward(): void
    {
        $this->melton();

        // The sheets name the shift once per block; the rows under it leave column E empty.
        $path = $this->workbook([
            13 => ['A' => self::TUESDAY, 'E' => 'Melton - AM', 'F' => 'R0450-4', 'P' => 0.5, 'S' => 87, 'AE' => 32],
            14 => ['A' => self::TUESDAY, 'F' => 'R0450-4', 'P' => 0.5, 'S' => 87, 'AE' => 32],
        ]);

        $report = $this->reconcile($path);

        $this->assertSame(2, $report->rowsCompared);
        $this->assertSame(2, $report->matched, 'A continuation row must not match every shift at the plant.');
    }

    public function test_a_plant_only_row_is_compared_against_the_whole_day(): void
    {
        // Somersby, whose workbook has no shift dimension and plans each row against a combined
        // 14.75 effective hours: (600 - 60) + (390 - 45) = 885 minutes.
        $depot = Depot::factory()->create(['code' => 'SOM', 'name' => 'Somersby', 'erp_entity_code' => 'NSW']);
        $machine = MachineAsset::factory()->create(['depot_id' => $depot->id, 'code' => 'SOM-EPAK1']);
        $day = $this->shiftFor($depot, 'DAY', 600, cleaning: 45);
        $afternoon = $this->shiftFor($depot, 'AFT', 390, cleaning: 30);

        foreach ([$day, $afternoon] as $shift) {
            OeeSetting::factory()->forShift($shift)->create([
                'effective_from' => '2026-01-01',
                'performance_percent' => 87,
                'availability_percent' => 87,
                'prestart_minutes' => 15,
                'in_shift_cleaning_minutes' => $shift->code === 'DAY' ? 45 : 30,
            ]);
        }

        $sku = SkuMaster::factory()->create(['sku_code' => 'R0675-3', 'erp_entity_code' => 'NSW']);
        MachineSkuRunRate::query()->create([
            'machine_asset_id' => $machine->id,
            'sku_master_id' => $sku->id,
            'rated_per_hour' => 38,
        ]);

        // 14.75 hr x 0.87 x 0.87 = 11.1642 effective; x 38/hr x 0.8 = 339.4, rounded up to 340.
        $path = $this->workbook([
            13 => ['A' => self::TUESDAY, 'E' => 'Somersby', 'F' => 'R0675-3', 'P' => 0.8, 'S' => 340, 'AE' => 38],
        ]);

        $report = $this->reconcile($path, entityCode: 'NSW');

        $this->assertSame(1, $report->matched, 'Rounding must happen once on the day, not per shift.');
        $this->assertStringContainsString('11.1642', $report->variances[0]['explanation'] ?? '11.1642');
    }

    public function test_a_share_of_shift_above_one_is_treated_as_overtime(): void
    {
        $this->melton();

        // Yatala records 1.11 for a day of overtime. Reading it as 1.11% would plan two pipes.
        // 9 hr x 0.6 = 5.4 effective, x 32/hr x 1.11 = 191.8, rounded up to 192.
        $path = $this->workbook([
            13 => ['A' => self::TUESDAY, 'E' => 'Melton - AM', 'F' => 'R0450-4', 'P' => 1.11, 'S' => 192, 'AE' => 32],
        ]);

        $this->assertSame(1, $this->reconcile($path)->matched);
    }

    public function test_a_whole_percentage_share_is_scaled_down(): void
    {
        $this->melton();

        $path = $this->workbook([
            13 => ['A' => self::TUESDAY, 'E' => 'Melton - AM', 'F' => 'R0450-4', 'P' => 50, 'S' => 87, 'AE' => 32],
        ]);

        $this->assertSame(1, $this->reconcile($path)->matched);
    }

    public function test_rows_planning_nothing_are_not_compared(): void
    {
        $this->melton();
        $path = $this->workbook([
            13 => ['A' => self::TUESDAY, 'E' => 'Melton - AM', 'F' => 'R0450-4', 'P' => 1, 'S' => 0, 'AE' => 32],
            14 => ['A' => self::TUESDAY, 'E' => 'Melton - AM', 'P' => 1, 'S' => 100],
        ]);

        $report = $this->reconcile($path);

        $this->assertSame(0, $report->rowsRead);
    }

    public function test_an_unknown_sku_is_reported_rather_than_counted_as_a_match(): void
    {
        $this->melton();
        $path = $this->workbook([
            13 => ['A' => self::TUESDAY, 'E' => 'Melton - AM', 'F' => 'NOT-A-SKU', 'P' => 1, 'S' => 100, 'AE' => 32],
        ]);

        $report = $this->reconcile($path);

        $this->assertSame(1, $report->rowsRead);
        $this->assertSame(0, $report->rowsCompared);
        $this->assertFalse($report->isReconciled());
        $this->assertArrayHasKey(
            'NOT-A-SKU is not in sku_master — import the BOM or sync products',
            $report->skipped,
        );
    }

    public function test_a_sku_with_no_run_rate_on_the_machine_is_reported(): void
    {
        $this->melton();
        SkuMaster::factory()->create(['sku_code' => 'R9999-9', 'standard_run_rate_per_hour' => 0]);

        $path = $this->workbook([
            13 => ['A' => self::TUESDAY, 'E' => 'Melton - AM', 'F' => 'R9999-9', 'P' => 1, 'S' => 100, 'AE' => 32],
        ]);

        $report = $this->reconcile($path);

        $this->assertSame(0, $report->rowsCompared);
        $this->assertSame(
            ['No run rate for R9999-9 on any MEL machine' => 1],
            $report->skipped,
        );
    }

    public function test_the_date_range_is_respected(): void
    {
        $this->melton();
        $path = $this->workbook([
            13 => ['A' => self::TUESDAY, 'E' => 'Melton - AM', 'F' => 'R0450-4', 'P' => 1, 'S' => 173, 'AE' => 32],
            14 => ['A' => self::TUESDAY + 60, 'E' => 'Melton - AM', 'F' => 'R0450-4', 'P' => 1, 'S' => 173, 'AE' => 32],
        ]);

        $report = app(WorkbookReconciler::class)->reconcile(
            path: $path,
            from: CarbonImmutable::parse('2026-09-01'),
            to: CarbonImmutable::parse('2026-09-30'),
        );

        $this->assertSame(1, $report->rowsRead);
    }

    public function test_excel_serial_dates_convert_correctly(): void
    {
        $this->assertSame('2026-09-01', XlsxReader::serialToDate(self::TUESDAY));
        $this->assertSame('2026-09-30', XlsxReader::serialToDate(46295));
        // Fractional serials carry a time of day the plan does not use.
        $this->assertSame('2026-09-01', XlsxReader::serialToDate(46266.75));
        $this->assertNull(XlsxReader::serialToDate(0));
    }

    private function reconcile(string $path, ?string $entityCode = null): ReconciliationReport
    {
        return app(WorkbookReconciler::class)->reconcile(path: $path, entityCode: $entityCode);
    }

    /**
     * Melton dayshift as the VIC workbook configures it, running R0450-4 at 32/hr.
     */
    private function melton(): Depot
    {
        $depot = Depot::factory()->create(['code' => 'MEL', 'name' => 'Melton', 'erp_entity_code' => 'VIC']);
        $machine = MachineAsset::factory()->create(['depot_id' => $depot->id, 'code' => 'MEL-BIDI36']);
        $shift = $this->shiftFor($depot, 'DAY', 600, cleaning: 45);

        OeeSetting::factory()->forShift($shift)->create([
            'effective_from' => '2026-01-01',
            'performance_percent' => 100,
            'availability_percent' => 60,
            'prestart_minutes' => 15,
            'in_shift_cleaning_minutes' => 45,
        ]);

        $sku = SkuMaster::factory()->create(['sku_code' => 'R0450-4']);
        MachineSkuRunRate::query()->create([
            'machine_asset_id' => $machine->id,
            'sku_master_id' => $sku->id,
            'rated_per_hour' => 32,
        ]);

        return $depot;
    }

    private function shiftFor(Depot $depot, string $code, int $minutes, int $cleaning): Shift
    {
        $shift = Shift::factory()->create([
            'depot_id' => $depot->id,
            'code' => $code,
            'starts_at' => $code === 'DAY' ? '06:00:00' : '16:00:00',
            'duration_minutes' => $minutes,
            'prestart_minutes' => 15,
            'cleaning_minutes' => $cleaning,
        ]);

        foreach ([1, 2, 3, 4, 5, 6] as $weekday) {
            DepotShiftPattern::factory()->create([
                'shift_id' => $shift->id,
                'weekday' => $weekday,
                'duration_minutes' => $minutes,
            ]);
        }

        return $shift;
    }

    /**
     * @param  array<int, array<string, string|int|float>>  $dataRows
     * @param  array<int, array<string, string|int|float>>  $headerOverrides
     */
    private function workbook(array $dataRows, array $headerOverrides = []): string
    {
        $headers = $headerOverrides !== [] ? $headerOverrides : [
            2 => [
                'A' => 'Manufacture Date', 'F' => 'PIPE', 'G' => 'Pipe machine',
                'O' => 'Pipes based on OEE Settings', 'P' => '% OF SHIFT',
                'S' => 'Planned Pipes (ea)', 'T' => 'GOOD PIPES', 'AB' => "Planned T's ",
                'AE' => 'Run rate at 100%',
                'AO' => '3.97', 'AP' => '4.95', 'AW' => 'TOTAL',
            ],
        ];

        return WorkbookFixture::make()
            ->withSheet('Prod  Plan', $headers + $dataRows)
            ->write(tempnam(sys_get_temp_dir(), 'wb').'.xlsx');
    }
}
