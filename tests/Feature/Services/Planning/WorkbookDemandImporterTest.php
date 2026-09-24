<?php

namespace Tests\Feature\Services\Planning;

use App\Models\Depot;
use App\Models\InventorySoh;
use App\Models\MachineAsset;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use App\Services\Planning\WorkbookDemandImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\WorkbookFixture;
use Tests\TestCase;

class WorkbookDemandImporterTest extends TestCase
{
    use RefreshDatabase;

    /** 1 September 2026 and 1 August 2026 as Excel serials. */
    private const SEP_2026 = 46266;

    private const AUG_2026 = 46235;

    private Depot $melton;

    private Depot $kilmore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->melton = Depot::factory()->create([
            'code' => 'MEL',
            'name' => 'Melton',
            'erp_entity_code' => 'RCPVIC',
            'prime_stock_locations' => ['31'],
            'rework_stock_locations' => ['33'],
        ]);
        $this->kilmore = Depot::factory()->create([
            'code' => 'KIL',
            'name' => 'Kilmore',
            'erp_entity_code' => 'RCPVIC',
            'prime_stock_locations' => ['32'],
            'rework_stock_locations' => ['34'],
        ]);

        MachineAsset::factory()->create([
            'depot_id' => $this->melton->id,
            'code' => 'MEL-BIDI84',
            'name' => 'BiDi 84',
        ]);
    }

    public function test_imports_the_order_book(): void
    {
        SkuMaster::factory()->create(['sku_code' => 'R0375-4', 'erp_entity_code' => 'RCPVIC', 'machine_preference' => 'BiDi 84']);

        $counts = $this->import();

        $this->assertSame(1, $counts['orders']);

        $order = SalesOrder::query()->sole();

        $this->assertSame(4288, $order->ordered_quantity);
        $this->assertSame('2026-09-01', $order->requested_delivery_date->toDateString());
        $this->assertSame('Precast Civil Industries', $order->customer_name);
        $this->assertSame('134691', $order->erp_order_reference);
        $this->assertSame('open', $order->status);
        // 'BiDi 84' is a Melton machine, so the demand belongs to Melton.
        $this->assertSame($this->melton->id, $order->depot_id);
    }

    public function test_splits_stock_into_prime_and_rework_by_location(): void
    {
        SkuMaster::factory()->create(['sku_code' => 'R0375-4', 'erp_entity_code' => 'RCPVIC', 'machine_preference' => 'BiDi 84']);

        $this->import();

        $rows = InventorySoh::query()->orderBy('bin_location')->get();

        $this->assertCount(2, $rows);
        $this->assertSame('31', $rows[0]->bin_location);
        $this->assertSame(120, $rows[0]->prime_quantity);
        $this->assertSame(0, $rows[0]->rework_quantity);
        $this->assertSame('33', $rows[1]->bin_location);
        $this->assertSame(0, $rows[1]->prime_quantity);
        $this->assertSame(15, $rows[1]->rework_quantity);
    }

    public function test_stock_is_attributed_to_the_plant_that_owns_the_location(): void
    {
        SkuMaster::factory()->create(['sku_code' => 'R0375-4', 'erp_entity_code' => 'RCPVIC', 'machine_preference' => 'BiDi 84']);

        $this->import();

        // Location 31 belongs to Melton and 33 to Melton's rework; neither is Kilmore's.
        $this->assertSame(0, InventorySoh::query()->where('depot_id', $this->kilmore->id)->count());
        $this->assertSame(2, InventorySoh::query()->where('depot_id', $this->melton->id)->count());
    }

    public function test_products_not_in_the_sku_master_are_skipped(): void
    {
        // Nothing seeded, so the workbook's product is unknown to the app.
        $counts = $this->import();

        $this->assertSame(0, $counts['orders']);
        $this->assertSame(0, $counts['stock rows']);
    }

    public function test_re_importing_replaces_rather_than_doubling_demand(): void
    {
        SkuMaster::factory()->create(['sku_code' => 'R0375-4', 'erp_entity_code' => 'RCPVIC', 'machine_preference' => 'BiDi 84']);

        $this->import();
        $this->import();

        $this->assertSame(1, SalesOrder::query()->count());
        $this->assertSame(2, InventorySoh::query()->count());
        $this->assertSame(4288, SalesOrder::query()->sole()->ordered_quantity);
    }

    public function test_an_unmapped_entity_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        app(WorkbookDemandImporter::class)->import($this->workbook(), 'RCPWA');
    }

    /**
     * @return array<string, int>
     */
    private function import(): array
    {
        return app(WorkbookDemandImporter::class)->import($this->workbook(), 'RCPVIC');
    }

    private function workbook(): string
    {
        return WorkbookFixture::make()
            ->withSheet('Outstanding Orders (OO)', [
                1 => [
                    'A' => 'STOCK_CODE', 'C' => 'OUTSTANDING_QTY', 'E' => 'DRS_MASTER.CUSTOMER_NAME',
                    'F' => 'REFERENCE_NBR', 'G' => 'PROJECT_NAME', 'L' => 'LINE_NBR',
                    'N' => 'CUSTOMER_CODE', 'Q' => 'SOR_MASTER.ORDER_DATE', 'S' => 'DELIVERY_DATE',
                ],
                2 => [
                    'A' => 'R0375-4', 'C' => 4288, 'E' => 'Precast Civil Industries',
                    'F' => '134691', 'G' => 'Southern Interchange', 'L' => '2',
                    'N' => '1913', 'Q' => self::AUG_2026, 'S' => self::SEP_2026,
                ],
                // No quantity left, so it is not demand.
                3 => ['A' => 'R0375-4', 'C' => 0, 'F' => '134692', 'L' => '1', 'S' => self::SEP_2026],
            ])
            ->withSheet('Stock OH', [
                1 => [
                    'A' => 'STOCK_CODE', 'F' => 'STK_LOCN_MASTER.STOCK_LOCATION',
                    'G' => 'STK_LOCN_MASTER.ON_HAND_QTY', 'K' => 'Good/Rework',
                ],
                2 => ['A' => 'R0375-4', 'F' => '31', 'G' => 120, 'K' => 'Good'],
                3 => ['A' => 'R0375-4', 'F' => '33', 'G' => 15, 'K' => 'Rework'],
            ])
            ->write(tempnam(sys_get_temp_dir(), 'ptm').'.xlsx');
    }
}
