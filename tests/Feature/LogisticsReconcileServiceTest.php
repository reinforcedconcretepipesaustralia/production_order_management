<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\OrderAllocation;
use App\Models\ProductionSchedule;
use App\Models\SkuMaster;
use App\Services\Scheduling\LogisticsReconcileService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Fixtures\SeedsLiveOrderBook;
use Tests\TestCase;

class LogisticsReconcileServiceTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SeedsLiveOrderBook;

    private Depot $depot;

    private SkuMaster $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installLiveOrderBook();
        $this->depot = Depot::factory()->create([
            'erp_entity_code' => 'RCPVIC',
            'prime_stock_locations' => ['31'],
        ]);
        $this->sku = SkuMaster::factory()->create(['erp_entity_code' => 'RCPVIC']);
    }

    public function test_reconcile_rebuilds_fifo_allocations_for_depot_and_sku(): void
    {
        $this->liveOrder($this->depot, $this->sku, 6, '2026-09-20', location: '31');
        $this->liveStock($this->depot, $this->sku, 4);

        ProductionSchedule::factory()
            ->for($this->depot)
            ->for($this->sku, 'sku')
            ->create([
                'schedule_date' => '2026-09-18',
                'planned_quantity' => 10,
                'status' => 'scheduled',
            ]);

        OrderAllocation::factory()->create([
            'depot_id' => $this->depot->id,
            'sku_master_id' => $this->sku->id,
            'quantity' => 99,
            'status' => 'allocated',
        ]);

        $result = app(LogisticsReconcileService::class)->reconcile($this->depot->id, $this->sku->id);

        $this->assertSame(1, $result->deletedAllocationRows);
        $this->assertSame(6, $result->totalAllocatedQuantity);
        $this->assertSame(0, $result->totalUnfulfilledQuantity);
        $this->assertSame(6, (int) OrderAllocation::query()->sum('quantity'));
    }
}
