<?php

namespace Tests\Feature\Services\Planning;

use App\Models\Depot;
use App\Models\InventorySoh;
use App\Models\ProductionSchedule;
use App\Models\SkuMaster;
use App\Services\Planning\DemandVsProdPlanWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Fixtures\SeedsLiveOrderBook;
use Tests\TestCase;

class DemandVsProdPlanWorkbookTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SeedsLiveOrderBook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installLiveOrderBook();
    }

    public function test_compares_outstanding_demand_to_the_production_plan_by_month(): void
    {
        $this->travelTo('2026-10-01 09:00:00');

        $depot = $this->plant('MEL', 'RCPVIC', '31');
        $otherDepot = $this->plant('SYD', 'RCPNSW', 'MAIN');
        $sku = SkuMaster::factory()->create(['sku_code' => 'R0675-4']);
        $hidden = SkuMaster::factory()->create(['sku_code' => 'HIDDEN-SKU']);

        InventorySoh::factory()->for($depot)->for($sku, 'sku')->create([
            'prime_quantity' => 232,
        ]);
        InventorySoh::factory()->for($otherDepot)->for($hidden, 'sku')->create([
            'prime_quantity' => 99,
        ]);

        $this->liveOrder($depot, $sku, 40, '2026-10-15', allocated: 19, location: '31');
        $this->liveOrder($depot, $sku, 35, '2026-11-02', location: '31');
        $this->liveOrder($otherDepot, $hidden, 50, '2026-10-15', location: 'MAIN');

        $this->schedule($depot, $sku, '2026-10-08', 58);
        $this->schedule($depot, $sku, '2026-11-09', 30);
        $this->schedule($depot, $sku, '2026-10-08', 12, status: 'cancelled');
        $this->schedule($otherDepot, $hidden, '2026-10-08', 80);

        $table = app(DemandVsProdPlanWorkbook::class)->table(
            collect([$depot]),
            ProductionSchedule::query(),
        );

        $this->assertSame(
            ['2026-10-01', '2026-11-01', '2026-12-01', '2027-01-01', '2027-02-01', '2027-03-01'],
            array_map(fn (CarbonImmutable $month): string => $month->toDateString(), $table['months']),
        );
        $this->assertCount(1, $table['rows']);
        $this->assertSame('R0675-4', $table['rows'][0]['sku_code']);
        $this->assertSame(232, $table['rows'][0]['soh']);
        $this->assertSame(21, $table['rows'][0]['demand']['2026-10-01']);
        $this->assertSame(35, $table['rows'][0]['demand']['2026-11-01']);
        $this->assertSame(58, $table['rows'][0]['plan']['2026-10-01']);
        $this->assertSame(30, $table['rows'][0]['plan']['2026-11-01']);
        $this->assertSame(37, $table['rows'][0]['variance']['2026-10-01']);
        $this->assertSame(-5, $table['rows'][0]['variance']['2026-11-01']);
        $this->assertSame(232, $table['totals']['soh']);
        $this->assertSame(37, $table['totals']['variance']['2026-10-01']);
        $this->assertSame(-5, $table['totals']['variance']['2026-11-01']);
    }

    public function test_rolls_overdue_demand_into_the_current_month(): void
    {
        $this->travelTo('2026-10-01 09:00:00');

        $depot = $this->plant('MEL', 'RCPVIC', '31');
        $sku = SkuMaster::factory()->create(['sku_code' => 'R0600-4']);

        $this->liveOrder($depot, $sku, 13, '2026-08-20', location: '31');
        $this->schedule($depot, $sku, '2026-10-06', 13);

        $table = app(DemandVsProdPlanWorkbook::class)->table(
            collect([$depot]),
            ProductionSchedule::query(),
        );

        $this->assertSame(13, $table['rows'][0]['demand']['2026-10-01']);
        $this->assertSame(0, $table['rows'][0]['demand']['2026-11-01']);
        $this->assertSame(0, $table['rows'][0]['variance']['2026-10-01']);
    }

    public function test_returns_an_empty_table_when_no_plants_are_in_scope(): void
    {
        $this->travelTo('2026-10-01 09:00:00');

        $table = app(DemandVsProdPlanWorkbook::class)->table(
            collect(),
            ProductionSchedule::query(),
        );

        $this->assertSame([], $table['rows']);
        $this->assertSame(0, $table['totals']['soh']);
        $this->assertCount(6, $table['months']);
    }

    private function schedule(Depot $depot, SkuMaster $sku, string $date, int $quantity, string $status = 'scheduled'): ProductionSchedule
    {
        $day = CarbonImmutable::parse($date);

        return ProductionSchedule::factory()->create([
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
            'schedule_date' => $date,
            'planned_start_at' => $day->addHours(6),
            'planned_end_at' => $day->addHours(14),
            'planned_quantity' => $quantity,
            'status' => $status,
        ]);
    }

    private function plant(string $code, string $entity, string $location): Depot
    {
        return Depot::factory()->create([
            'code' => $code,
            'erp_entity_code' => $entity,
            'prime_stock_locations' => [$location],
        ]);
    }
}
