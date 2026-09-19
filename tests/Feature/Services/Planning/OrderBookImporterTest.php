<?php

namespace Tests\Feature\Services\Planning;

use App\Models\Depot;
use App\Models\MachineAsset;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use App\Services\Planning\OrderBookImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\WorkbookFixture;
use Tests\TestCase;

/**
 * The national extract is the order book the planner ranks on, so the rules about whose work it
 * is are pinned down here — including the two entities that are not plants.
 */
class OrderBookImporterTest extends TestCase
{
    /** 1 September 2026 and 1 December 2026 as Excel serials. */
    private const SEP_2026 = 46266;

    private const DEC_2026 = 46357;

    use RefreshDatabase;

    private Depot $melton;

    private Depot $somersby;

    protected function setUp(): void
    {
        parent::setUp();

        $this->melton = Depot::factory()->create([
            'code' => 'MEL', 'name' => 'Melton', 'erp_entity_code' => 'RCPVIC',
        ]);
        $this->somersby = Depot::factory()->create([
            'code' => 'SOM', 'name' => 'Somersby', 'erp_entity_code' => 'RCPNSW',
        ]);
        MachineAsset::factory()->create(['depot_id' => $this->melton->id, 'name' => 'BiDi 84']);
    }

    public function test_imports_an_order_with_its_delivery_date(): void
    {
        $this->sku('R0600-4', 600);

        // The Victorian line, plus the South Australian 600 that Victoria supplies.
        $counts = $this->import();

        $this->assertSame(2, $counts['imported']);

        $order = SalesOrder::query()->where('erp_order_reference', '113785')->sole();

        $this->assertSame(648, $order->ordered_quantity);
        $this->assertSame(0, $order->allocated_quantity, 'Outstanding Qty is already net of shipments.');
        $this->assertSame('2026-12-01', $order->requested_delivery_date->toDateString());
        $this->assertSame('2026-09-01', $order->ordered_at->toDateString());
        $this->assertSame('LOGCIT', $order->customer_name);
        $this->assertSame($this->melton->id, $order->depot_id);
    }

    public function test_the_closing_western_australian_plant_is_left_out(): void
    {
        $this->sku('R0600-4', 600);
        $this->sku('R0300-4', 300);

        $counts = $this->import();

        $this->assertSame(1, $counts['skipped (closing plant)']);
        $this->assertSame(0, SalesOrder::query()->where('erp_order_reference', 'WA-1')->count());
    }

    /**
     * South Australia holds stock but makes nothing, so its orders are real work for the plants
     * that supply it rather than demand to be dropped.
     */
    public function test_south_australian_demand_is_routed_to_the_plant_that_supplies_it(): void
    {
        $this->sku('R0375-4', 375);
        $this->sku('R0600-4', 600);

        $counts = $this->import();

        $this->assertSame(2, $counts['routed from a holding yard']);

        // NSW supplies 375 and 450 to South Australia.
        $this->assertSame(
            $this->somersby->id,
            SalesOrder::query()->where('erp_order_reference', 'SA-375')->sole()->depot_id,
        );
        // Victoria supplies everything larger.
        $this->assertSame(
            $this->melton->id,
            SalesOrder::query()->where('erp_order_reference', 'SA-600')->sole()->depot_id,
        );
    }

    public function test_products_missing_from_the_master_are_counted_not_guessed(): void
    {
        $counts = $this->import();

        $this->assertSame(0, $counts['imported']);
        $this->assertGreaterThan(0, $counts['skipped (unknown product)']);
        $this->assertSame(0, SalesOrder::query()->count());
    }

    public function test_an_entity_with_no_plant_is_reported(): void
    {
        $this->sku('R0600-4', 600);
        $this->sku('R0375-4', 375);
        $this->somersby->delete();

        $counts = $this->import();

        // The South Australian 375 has nowhere to go once NSW has no plant.
        $this->assertSame(1, $counts['skipped (no plant for entity)']);
    }

    public function test_re_importing_replaces_the_order_book(): void
    {
        $this->sku('R0600-4', 600);

        $this->import();
        $this->import();

        $this->assertSame(1, SalesOrder::query()->where('erp_order_reference', '113785')->count());
    }

    public function test_a_file_without_the_expected_sheet_is_rejected(): void
    {
        $path = WorkbookFixture::make()
            ->withSheet('Something Else', [1 => ['A' => 'Nope']])
            ->write(tempnam(sys_get_temp_dir(), 'ob').'.xlsx');

        $this->expectException(\RuntimeException::class);

        app(OrderBookImporter::class)->import($path);
    }

    private function sku(string $code, int $diameter): SkuMaster
    {
        return SkuMaster::factory()->create([
            'sku_code' => $code,
            'diameter_mm' => $diameter,
            'machine_preference' => 'BiDi 84',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function import(): array
    {
        return app(OrderBookImporter::class)->import($this->workbook());
    }

    private function workbook(): string
    {
        return WorkbookFixture::make()
            ->withSheet('Outstanding Order', [
                1 => [
                    'A' => 'Entity', 'B' => 'Sales Order', 'C' => 'Order Date', 'D' => 'Stock Code',
                    'H' => 'Line ID', 'K' => 'Outstanding Qty', 'V' => 'Delivery Date',
                    'Z' => 'Customer Code', 'AA' => 'Customer Name', 'AD' => 'Project Name',
                ],
                2 => [
                    'A' => 'RCPVIC', 'B' => '113785', 'C' => self::SEP_2026, 'D' => 'R0600-4',
                    'H' => '1', 'K' => 648, 'V' => self::DEC_2026,
                    'Z' => '1008', 'AA' => 'LOGCIT', 'AD' => 'Chambers Flat Road',
                ],
                // Western Australia is closing.
                3 => [
                    'A' => 'RCPWA', 'B' => 'WA-1', 'C' => self::SEP_2026, 'D' => 'R0300-4',
                    'H' => '1', 'K' => 100, 'V' => self::DEC_2026,
                ],
                // South Australia holds stock but makes nothing.
                4 => [
                    'A' => 'RCPSA', 'B' => 'SA-375', 'C' => self::SEP_2026, 'D' => 'R0375-4',
                    'H' => '1', 'K' => 50, 'V' => self::DEC_2026,
                ],
                5 => [
                    'A' => 'RCPSA', 'B' => 'SA-600', 'C' => self::SEP_2026, 'D' => 'R0600-4',
                    'H' => '1', 'K' => 60, 'V' => self::DEC_2026,
                ],
                // Nothing left to make.
                6 => [
                    'A' => 'RCPVIC', 'B' => '113786', 'C' => self::SEP_2026, 'D' => 'R0600-4',
                    'H' => '1', 'K' => 0, 'V' => self::DEC_2026,
                ],
            ])
            ->write(tempnam(sys_get_temp_dir(), 'ob').'.xlsx');
    }
}
