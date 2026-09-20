<?php

namespace Tests\Feature\Services\Sync;

use App\Models\Depot;
use App\Models\InventorySoh;
use App\Models\ProductionHistory;
use App\Models\SalesHistoryMonthly;
use App\Models\SkuMaster;
use App\Services\Sync\ProductionHistorySync;
use App\Services\Sync\SalesHistorySync;
use App\Services\Sync\StockSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\FakeTenciaSource;
use Tests\TestCase;

class StockAndHistorySyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeTenciaSource $tencia;

    private Depot $melton;

    private Depot $kilmore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tencia = FakeTenciaSource::install()->createTables();

        $this->melton = Depot::factory()->create([
            'code' => 'MEL', 'name' => 'Melton', 'erp_entity_code' => 'RCPVIC',
            'erp_stock_categories' => ['VA'],
            'prime_stock_locations' => ['31'],
            'rework_stock_locations' => ['33'],
            'quarantine_stock_locations' => ['QS'],
        ]);
        $this->kilmore = Depot::factory()->create([
            'code' => 'KIL', 'name' => 'Kilmore', 'erp_entity_code' => 'RCPVIC',
            'erp_stock_categories' => ['VA'],
            'prime_stock_locations' => ['32'],
            'rework_stock_locations' => ['34'],
        ]);
    }

    /**
     * Rework is real stock that can cover an order; quarantine cannot. Collapsing them overstates
     * what is available to ship.
     */
    public function test_stock_is_split_by_what_each_location_holds(): void
    {
        $sku = $this->sku('R0600-4');

        $this->tencia
            ->addStock('RCPVIC', ['STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31', 'ON_HAND_QTY' => 400])
            ->addStock('RCPVIC', ['STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '33', 'ON_HAND_QTY' => 51])
            ->addStock('RCPVIC', ['STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => 'QS', 'ON_HAND_QTY' => 7]);

        app(StockSync::class)->sync();

        $position = InventorySoh::query()->where('depot_id', $this->melton->id)->sole();

        $this->assertSame($sku->id, $position->sku_master_id);
        $this->assertSame(400, $position->prime_quantity);
        $this->assertSame(51, $position->rework_quantity);
        $this->assertSame(7, $position->quarantine_quantity);
    }

    public function test_each_plant_keeps_its_own_yard(): void
    {
        $this->sku('R0600-4');

        $this->tencia
            ->addStock('RCPVIC', ['STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31', 'ON_HAND_QTY' => 400])
            ->addStock('RCPVIC', ['STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '32', 'ON_HAND_QTY' => 120]);

        app(StockSync::class)->sync();

        $this->assertSame(400, InventorySoh::query()->where('depot_id', $this->melton->id)->sole()->prime_quantity);
        $this->assertSame(120, InventorySoh::query()->where('depot_id', $this->kilmore->id)->sole()->prime_quantity);
    }

    /**
     * Tencia carries negative balances. They are counted so the discrepancy stays visible, but they
     * cannot be planned against as stock.
     */
    public function test_a_negative_balance_is_reported_and_floored(): void
    {
        $this->sku('R0600-4');

        $this->tencia->addStock('RCPVIC', [
            'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31', 'ON_HAND_QTY' => -32,
        ]);

        $counts = app(StockSync::class)->sync()->counts();

        $this->assertSame(1, $counts['locations with negative stock in Tencia']);
        $this->assertSame(0, InventorySoh::query()->sole()->prime_quantity);
    }

    public function test_a_yard_no_plant_owns_is_not_counted_as_plant_stock(): void
    {
        $this->sku('R0600-4');

        // 35 is Melton Precast, which is not pipe-plant stock.
        $this->tencia->addStock('RCPVIC', [
            'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '35', 'ON_HAND_QTY' => 300,
        ]);

        $counts = app(StockSync::class)->sync()->counts();

        $this->assertSame(1, $counts['skipped (location not a plant yard)']);
        $this->assertSame(0, InventorySoh::query()->count());
    }

    public function test_re_syncing_stock_updates_rather_than_duplicates(): void
    {
        $this->sku('R0600-4');

        $this->tencia->addStock('RCPVIC', [
            'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31', 'ON_HAND_QTY' => 400,
        ]);

        app(StockSync::class)->sync();
        app(StockSync::class)->sync();

        $this->assertSame(1, InventorySoh::query()->count());
    }

    /**
     * A returned pipe did not sell, so credits come off the month rather than adding to it.
     */
    public function test_credits_are_netted_off_the_month(): void
    {
        $this->sku('R0600-4');

        $this->tencia
            ->addSale(['STOCK_CODE' => 'R0600-4', 'DATE' => '2026-08-10 00:00:00', 'QUANTITY' => 100, 'Line_Weight' => 59.4])
            ->addSale(['STOCK_CODE' => 'R0600-4', 'DATE' => '2026-08-20 00:00:00', 'QUANTITY' => 60, 'Line_Weight' => 35.6])
            ->addSale([
                'STOCK_CODE' => 'R0600-4', 'TRANS_TYPE' => 'DRCDT',
                'DATE' => '2026-08-25 00:00:00', 'QUANTITY' => 10, 'Line_Weight' => 5.9,
            ]);

        app(SalesHistorySync::class)->sync();

        $month = SalesHistoryMonthly::query()->sole();

        $this->assertSame('2026-08-01', $month->month->toDateString());
        $this->assertSame(150, $month->quantity_ea);
        $this->assertSame('89.1000', $month->tonnes);
    }

    public function test_sales_are_split_by_month(): void
    {
        $this->sku('R0600-4');

        $this->tencia
            ->addSale(['STOCK_CODE' => 'R0600-4', 'DATE' => '2026-07-10 00:00:00', 'QUANTITY' => 10])
            ->addSale(['STOCK_CODE' => 'R0600-4', 'DATE' => '2026-08-10 00:00:00', 'QUANTITY' => 20]);

        app(SalesHistorySync::class)->sync();

        $this->assertSame(
            ['2026-07-01' => 10, '2026-08-01' => 20],
            SalesHistoryMonthly::query()->orderBy('month')->get()
                ->mapWithKeys(fn ($row): array => [$row->month->toDateString() => $row->quantity_ea])->all(),
        );
    }

    /**
     * Re-running must not duplicate a month. The date cast persists a midnight time, so matching on a
     * 'Y-m-d' string never finds the existing row — this codebase has been bitten by that repeatedly.
     */
    public function test_re_syncing_sales_updates_the_same_month(): void
    {
        $this->sku('R0600-4');

        $this->tencia->addSale(['STOCK_CODE' => 'R0600-4', 'DATE' => '2026-08-10 00:00:00', 'QUANTITY' => 10]);

        app(SalesHistorySync::class)->sync();
        app(SalesHistorySync::class)->sync();

        $this->assertSame(1, SalesHistoryMonthly::query()->count());
    }

    /**
     * vwProduction is BOM-exploded: the good-pipe count repeats on every component row, so summing
     * it multiplies production by the number of components.
     */
    public function test_a_run_is_counted_once_however_many_components_its_bill_has(): void
    {
        $sku = $this->sku('R0300-4');

        foreach (['WIREPL6.23', 'WIREPL4.95', 'MEL-BIDI', 'LAB - MEL PIPES', 'OH - MEL PIPES'] as $component) {
            $this->tencia->addProductionRow([
                'PROD_ORD_NBR' => '127345', 'BILL_STOCK_CODE' => 'R0300-4', 'STOCK_CODE' => $component,
                'ORDER_DATE' => '2026-09-18 00:00:00',
                'GOOD_PIPES' => 333, 'REJECTED_PIPES' => 10, 'BILL_TONNES_PRODUCED' => 82.92,
            ]);
        }

        $counts = app(ProductionHistorySync::class)->sync()->counts();

        $this->assertSame(1, $counts['production runs']);

        $run = ProductionHistory::query()->sole();

        $this->assertSame(333, $run->good_quantity);
        $this->assertSame(10, $run->rejected_quantity);
        $this->assertSame('82.9200', $run->tonnes_produced);
        $this->assertSame($sku->id, $run->sku_master_id);
        $this->assertSame(97.08, $run->qualityPercent());
    }

    /**
     * The same order number recurs in Tencia with a different duplicate and a different good-pipe
     * count, so the number alone is not the key.
     */
    public function test_the_same_order_number_with_a_different_duplicate_is_a_different_run(): void
    {
        $this->sku('R0300-4');

        $this->tencia
            ->addProductionRow([
                'PROD_ORD_NBR' => '100004', 'PROD_ORD_DUPLICATE' => 0, 'BILL_STOCK_CODE' => 'R0300-4',
                'ORDER_DATE' => '2026-09-01 00:00:00', 'GOOD_PIPES' => 100,
            ])
            ->addProductionRow([
                'PROD_ORD_NBR' => '100004', 'PROD_ORD_DUPLICATE' => 1, 'BILL_STOCK_CODE' => 'R0300-4',
                'ORDER_DATE' => '2026-09-02 00:00:00', 'GOOD_PIPES' => 250,
            ]);

        app(ProductionHistorySync::class)->sync();

        $this->assertSame(
            [100, 250],
            ProductionHistory::query()->orderBy('erp_order_duplicate')->pluck('good_quantity')->all(),
        );
    }

    public function test_only_closed_runs_are_history(): void
    {
        $this->sku('R0300-4');

        $this->tencia
            ->addProductionRow([
                'PROD_ORD_NBR' => 'OPEN', 'BILL_STOCK_CODE' => 'R0300-4',
                'STATUS' => 'Allocated Stock', 'ORDER_DATE' => '2026-09-01 00:00:00',
            ])
            ->addProductionRow([
                'PROD_ORD_NBR' => 'DONE', 'BILL_STOCK_CODE' => 'R0300-4',
                'ORDER_DATE' => '2026-09-01 00:00:00', 'GOOD_PIPES' => 50,
            ]);

        app(ProductionHistorySync::class)->sync();

        $this->assertSame(['DONE'], ProductionHistory::query()->pluck('erp_order_number')->all());
    }

    /**
     * A run of something no longer in the product master is still history worth keeping; it just
     * cannot be linked to a product.
     */
    public function test_a_run_of_a_retired_product_is_kept_and_flagged(): void
    {
        $this->tencia->addProductionRow([
            'PROD_ORD_NBR' => '99', 'BILL_STOCK_CODE' => 'GONE-1',
            'ORDER_DATE' => '2026-09-01 00:00:00', 'GOOD_PIPES' => 20,
        ]);

        $counts = app(ProductionHistorySync::class)->sync()->counts();

        $this->assertSame(1, $counts['production runs']);
        $this->assertSame(1, $counts['runs of a product not in the master']);
        $this->assertNull(ProductionHistory::query()->sole()->sku_master_id);
        $this->assertSame('GONE-1', ProductionHistory::query()->sole()->sku_code);
    }

    private function sku(string $code): SkuMaster
    {
        return SkuMaster::factory()->create([
            'sku_code' => $code, 'erp_entity_code' => 'RCPVIC',
        ]);
    }
}
