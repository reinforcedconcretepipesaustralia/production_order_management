<?php

namespace Tests\Feature\Services\Sync;

use App\Models\Depot;
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

        $byKind = app(StockSync::class)->forDepot($this->melton)->get($sku->id);

        $this->assertSame(400, $byKind['prime']);
        $this->assertSame(51, $byKind['rework']);
        $this->assertSame(7, (int) app(StockSync::class)->positions()
            ->where('depot_id', $this->melton->id)
            ->where('kind', 'quarantine')
            ->sum('on_hand'));
    }

    public function test_each_plant_keeps_its_own_yard(): void
    {
        $this->sku('R0600-4');

        $this->tencia
            ->addStock('RCPVIC', ['STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31', 'ON_HAND_QTY' => 400])
            ->addStock('RCPVIC', ['STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '32', 'ON_HAND_QTY' => 120]);

        $stock = app(StockSync::class);

        $this->assertSame(400, $stock->forDepot($this->melton)->first()['prime']);
        $this->assertSame(120, $stock->forDepot($this->kilmore)->first()['prime']);
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

        $stock = app(StockSync::class);
        $stock->positions();

        $this->assertSame(1, $stock->result()->counts()['locations with negative stock in Tencia']);
        $this->assertSame(0, $stock->forDepot($this->melton)->first()['prime'] ?? 0);
    }

    public function test_a_yard_no_plant_owns_is_not_counted_as_plant_stock(): void
    {
        $this->sku('R0600-4');

        // 35 is Melton Precast, which is not pipe-plant stock.
        $this->tencia->addStock('RCPVIC', [
            'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '35', 'ON_HAND_QTY' => 300,
        ]);

        $stock = app(StockSync::class);
        $stock->positions();

        $this->assertSame(1, $stock->result()->counts()['skipped (location not a plant yard)']);
        $this->assertCount(0, $stock->positions());
    }

    public function test_re_syncing_stock_updates_rather_than_duplicates(): void
    {
        $this->sku('R0600-4');

        $this->tencia->addStock('RCPVIC', [
            'STOCK_CODE' => 'R0600-4', 'STOCK_LOCATION' => '31', 'ON_HAND_QTY' => 400,
        ]);

        $this->assertCount(1, app(StockSync::class)->positions());
        $this->assertDatabaseCount('inventory_soh', 0);
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

        $month = app(SalesHistorySync::class)->months()->sole();

        $this->assertSame('2026-08-01', $month['month']);
        $this->assertSame(150, $month['quantity_ea']);
        $this->assertSame(89.1, $month['tonnes']);
    }

    public function test_sales_are_split_by_month(): void
    {
        $this->sku('R0600-4');

        $this->tencia
            ->addSale(['STOCK_CODE' => 'R0600-4', 'DATE' => '2026-07-10 00:00:00', 'QUANTITY' => 10])
            ->addSale(['STOCK_CODE' => 'R0600-4', 'DATE' => '2026-08-10 00:00:00', 'QUANTITY' => 20]);

        $this->assertSame(
            ['2026-07-01' => 10, '2026-08-01' => 20],
            app(SalesHistorySync::class)->months()
                ->sortBy('month')
                ->mapWithKeys(fn (array $row): array => [$row['month'] => $row['quantity_ea']])
                ->all(),
        );
    }

    public function test_re_reading_sales_returns_the_same_month_once(): void
    {
        $this->sku('R0600-4');

        $this->tencia->addSale(['STOCK_CODE' => 'R0600-4', 'DATE' => '2026-08-10 00:00:00', 'QUANTITY' => 10]);

        $this->assertCount(1, app(SalesHistorySync::class)->months());
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

        $history = app(ProductionHistorySync::class);
        $run = $history->runs()->sole();

        $this->assertSame(1, $history->result()->counts()['production runs']);
        $this->assertSame(333, $run['good_quantity']);
        $this->assertSame(10, $run['rejected_quantity']);
        $this->assertSame(82.92, $run['tonnes_produced']);
        $this->assertSame($sku->id, $run['sku_master_id']);
        $this->assertSame(97.08, $run['quality_percent']);
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

        $this->assertSame(
            [100, 250],
            app(ProductionHistorySync::class)->runs()
                ->sortBy('erp_order_duplicate')
                ->pluck('good_quantity')
                ->all(),
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

        $this->assertSame(
            ['DONE'],
            app(ProductionHistorySync::class)->runs()->pluck('erp_order_number')->all(),
        );
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

        $history = app(ProductionHistorySync::class);
        $run = $history->runs()->sole();

        $this->assertSame(1, $history->result()->counts()['production runs']);
        $this->assertSame(1, $history->result()->counts()['runs of a product not in the master']);
        $this->assertNull($run['sku_master_id']);
        $this->assertSame('GONE-1', $run['sku_code']);
    }

    private function sku(string $code): SkuMaster
    {
        return SkuMaster::factory()->create([
            'sku_code' => $code, 'erp_entity_code' => 'RCPVIC',
        ]);
    }
}
