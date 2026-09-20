<?php

namespace Tests\Feature\Services\Sync;

use App\Models\Depot;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use App\Services\Sync\OrderSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeTenciaSource;
use Tests\TestCase;

/**
 * The order book is what the priority rule ranks on, so whose work a line is, and how much of it is
 * still to make, are pinned down here.
 */
class OrderSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeTenciaSource $tencia;

    private Depot $melton;

    private Depot $kilmore;

    private Depot $somersby;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tencia = FakeTenciaSource::install()->createTables();

        $this->melton = Depot::factory()->create([
            'code' => 'MEL', 'name' => 'Melton', 'erp_entity_code' => 'RCPVIC',
            'erp_stock_categories' => ['VA'],
            'prime_stock_locations' => ['31'], 'rework_stock_locations' => ['33'],
        ]);
        $this->kilmore = Depot::factory()->create([
            'code' => 'KIL', 'name' => 'Kilmore', 'erp_entity_code' => 'RCPVIC',
            'erp_stock_categories' => ['VA'],
            'prime_stock_locations' => ['32'], 'rework_stock_locations' => ['34'],
        ]);
        $this->somersby = Depot::factory()->create([
            'code' => 'SOM', 'name' => 'Somersby', 'erp_entity_code' => 'RCPNSW',
            'erp_stock_categories' => ['NA'], 'prime_stock_locations' => ['MAIN'],
        ]);
    }

    public function test_imports_the_backorder_with_its_line_delivery_date(): void
    {
        $sku = $this->sku('RCPVIC', 'R0600-4');

        $this->tencia->addOrderLine('RCPVIC', [
            'UNIQUE_ID' => 173079, 'REFERENCE_NBR' => '136510', 'LINE_NBR' => 33,
            'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31',
            'BACKORDER_QTY' => 648, 'DELIVERY_DATE' => '2026-12-01 00:00:00',
            'CUSTOMER_CODE' => '1008',
        ], ['ORDER_DATE' => '2026-09-01 00:00:00', 'DELIVERY_NAME' => 'LOGCIT']);

        $counts = app(OrderSync::class)->sync()->counts();

        $this->assertSame(1, $counts['orders']);

        $order = SalesOrder::query()->sole();

        $this->assertSame('136510', $order->erp_order_reference);
        $this->assertSame('173079', $order->erp_line_reference, 'UNIQUE_ID survives renumbering.');
        $this->assertSame(648, $order->ordered_quantity);
        $this->assertSame(0, $order->allocated_quantity, 'A backorder has nothing allocated yet.');
        $this->assertSame('2026-12-01', $order->requested_delivery_date->toDateString());
        $this->assertSame('LOGCIT', $order->customer_name);
        $this->assertSame($sku->id, $order->sku_master_id);
    }

    /**
     * Two plants share a state, and the line's stock location is the ERP's own answer to which of
     * them the work belongs to.
     */
    public function test_the_stock_location_decides_which_plant_makes_it(): void
    {
        $this->sku('RCPVIC', 'R0600-4');

        $this->tencia
            ->addOrderLine('RCPVIC', ['UNIQUE_ID' => 1, 'REFERENCE_NBR' => 'A', 'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31'])
            ->addOrderLine('RCPVIC', ['UNIQUE_ID' => 2, 'REFERENCE_NBR' => 'B', 'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '32']);

        app(OrderSync::class)->sync();

        $this->assertSame($this->melton->id, SalesOrder::query()->where('erp_line_reference', '1')->sole()->depot_id);
        $this->assertSame($this->kilmore->id, SalesOrder::query()->where('erp_line_reference', '2')->sole()->depot_id);
    }

    public function test_a_line_with_nothing_left_to_make_is_not_imported(): void
    {
        $this->sku('RCPVIC', 'R0600-4');

        $this->tencia->addOrderLine('RCPVIC', [
            'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31', 'BACKORDER_QTY' => 0,
        ]);

        app(OrderSync::class)->sync();

        $this->assertSame(0, SalesOrder::query()->count());
    }

    public function test_cancelled_orders_and_lines_are_left_out(): void
    {
        $this->sku('RCPVIC', 'R0600-4');

        $this->tencia
            ->addOrderLine('RCPVIC', [
                'UNIQUE_ID' => 1, 'REFERENCE_NBR' => 'CANCELLED-HEADER',
                'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31',
            ], ['STATUS_FLAG' => 'C'])
            ->addOrderLine('RCPVIC', [
                'UNIQUE_ID' => 2, 'REFERENCE_NBR' => 'CANCELLED-LINE',
                'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31', 'LINE_STATUS' => 'C',
            ])
            ->addOrderLine('RCPVIC', [
                'UNIQUE_ID' => 3, 'REFERENCE_NBR' => 'OPEN',
                'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31',
            ]);

        app(OrderSync::class)->sync();

        $this->assertSame(['OPEN'], SalesOrder::query()->pluck('erp_order_reference')->all());
    }

    /**
     * South Australia holds stock but makes nothing. Its codes carry the supplying state as a first
     * letter, which settles the 450 that both VIC and NSW make — the line itself says which.
     */
    public function test_holding_yard_demand_is_routed_by_the_state_its_code_names(): void
    {
        $nswSku = $this->sku('RCPNSW', 'R0450-4');
        $vicSku = $this->sku('RCPVIC', 'R0450-4');

        $this->tencia
            ->addOrderLine('RCPSA', ['UNIQUE_ID' => 1, 'REFERENCE_NBR' => 'SA-N', 'STOCK_CODE' => 'NR0450-4', 'STOCK_LOCATION' => '50'])
            ->addOrderLine('RCPSA', ['UNIQUE_ID' => 2, 'REFERENCE_NBR' => 'SA-V', 'STOCK_CODE' => 'VR0450-4', 'STOCK_LOCATION' => '50']);

        $counts = app(OrderSync::class)->sync()->counts();

        $this->assertSame(2, $counts['routed from a holding yard']);

        $toNsw = SalesOrder::query()->where('erp_order_reference', 'SA-N')->sole();
        $toVic = SalesOrder::query()->where('erp_order_reference', 'SA-V')->sole();

        $this->assertSame($nswSku->id, $toNsw->sku_master_id);
        $this->assertSame($this->somersby->id, $toNsw->depot_id);
        $this->assertSame($vicSku->id, $toVic->sku_master_id);
        $this->assertSame($this->melton->id, $toVic->depot_id);
    }

    /**
     * The closing plant still appears as a source on the holding yard's books. That demand has to be
     * re-sourced, so it is reported rather than quietly dropped.
     */
    public function test_demand_sourced_from_the_closing_plant_is_reported(): void
    {
        $this->tencia->addOrderLine('RCPSA', [
            'REFERENCE_NBR' => 'SA-W', 'STOCK_CODE' => 'WR0375-4-NOR-P', 'STOCK_LOCATION' => '50',
        ]);

        $result = app(OrderSync::class)->sync();

        $this->assertSame(1, $result->counts()['skipped (sourced from the closing plant)']);
        $this->assertStringContainsString('RCPWA', implode(' ', $result->rejects()));
        $this->assertSame(0, SalesOrder::query()->count());
    }

    /**
     * A pipe order carries accessories — rubber rings, sand bands, caps — that no pipe machine makes.
     * Those are expected, and must not bury a product genuinely missing from the master.
     */
    public function test_accessories_are_counted_by_category_not_reported_as_unknown(): void
    {
        $this->sku('RCPVIC', 'R0600-4');
        $this->tencia->addProduct(['ENTITY' => 'RCPVIC', 'STOCK_CODE' => 'RR0600', 'STOCK_CATEGORY' => 'VG']);

        $this->tencia
            ->addOrderLine('RCPVIC', ['UNIQUE_ID' => 1, 'REFERENCE_NBR' => 'A', 'STOCK_CODE' => 'RR0600', 'STOCK_LOCATION' => '31'])
            ->addOrderLine('RCPVIC', ['UNIQUE_ID' => 2, 'REFERENCE_NBR' => 'B', 'STOCK_CODE' => 'NOT-IN-TENCIA', 'STOCK_LOCATION' => '31']);

        $result = app(OrderSync::class)->sync();

        $this->assertSame(1, $result->counts()['skipped (category VG, not a planned pipe)']);
        $this->assertSame(1, $result->counts()['skipped (not in Tencia stock master)']);
        $this->assertStringContainsString('NOT-IN-TENCIA', implode(' ', $result->rejects()));
    }

    public function test_re_syncing_replaces_the_order_book_rather_than_doubling_it(): void
    {
        $this->sku('RCPVIC', 'R0600-4');

        $this->tencia->addOrderLine('RCPVIC', [
            'REFERENCE_NBR' => '136510', 'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31',
        ]);

        app(OrderSync::class)->sync();
        app(OrderSync::class)->sync();

        $this->assertSame(1, SalesOrder::query()->count());
    }

    public function test_keeping_existing_orders_leaves_a_manually_loaded_book_in_place(): void
    {
        $sku = $this->sku('RCPVIC', 'R0600-4');

        SalesOrder::factory()->create([
            'depot_id' => $this->melton->id, 'sku_master_id' => $sku->id,
            'erp_order_reference' => 'FROM-SPREADSHEET', 'erp_line_reference' => '1',
        ]);

        $this->tencia->addOrderLine('RCPVIC', [
            'REFERENCE_NBR' => '136510', 'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31',
        ]);

        app(OrderSync::class)->sync(replace: false);

        $this->assertSame(2, SalesOrder::query()->count());
    }

    private function sku(string $entity, string $code): SkuMaster
    {
        return SkuMaster::factory()->create([
            'sku_code' => $code, 'erp_entity_code' => $entity,
            'diameter_mm' => (int) substr($code, 1, 4),
        ]);
    }
}
