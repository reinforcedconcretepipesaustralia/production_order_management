<?php

namespace Tests\Feature\Services\Scheduling;

use App\Exceptions\InvalidOrderAllocationException;
use App\Models\Depot;
use App\Models\OrderAllocation;
use App\Models\ProductionSchedule;
use App\Models\SkuMaster;
use App\Services\Scheduling\FifoAllocationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Fixtures\SeedsLiveOrderBook;
use Tests\TestCase;

class FifoAllocationServiceTest extends TestCase
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

    public function test_allocates_only_prime_stock_in_order_and_delivery_fifo_order(): void
    {
        $oldest = $this->createOrder('2026-09-01 08:00:00', '2026-09-20');
        $laterDelivery = $this->createOrder('2026-09-02 08:00:00', '2026-09-25');
        $earlierDelivery = $this->createOrder('2026-09-02 08:00:00', '2026-09-22');
        $this->liveStock($this->depot, $this->sku, 12);

        $result = app(FifoAllocationService::class)->allocatePrimeStock($this->depot, $this->sku);

        $this->assertSame(12, $result->allocatedQuantity);
        $this->assertSame(3, $result->unfulfilledQuantity);
        $this->assertSame(
            [$oldest, $earlierDelivery, $laterDelivery],
            OrderAllocation::query()->orderBy('id')->pluck('erp_line_reference')->all(),
        );
        $this->assertSame(['31', '31', '31'], OrderAllocation::query()->orderBy('id')->pluck('stock_location')->all());
        $this->assertSame(0, OrderAllocation::query()->whereNotNull('inventory_soh_id')->count());
    }

    public function test_never_allocates_other_depot_or_sku_and_cannot_over_allocate_on_repeat(): void
    {
        $otherDepot = Depot::factory()->create([
            'erp_entity_code' => 'RCPNSW',
            'prime_stock_locations' => ['MAIN'],
        ]);
        $otherSku = SkuMaster::factory()->create(['erp_entity_code' => 'RCPVIC']);
        $this->createOrder('2026-09-01 08:00:00', '2026-09-20', 4);
        $nswSku = SkuMaster::factory()->create(['erp_entity_code' => 'RCPNSW']);
        $this->liveOrder($otherDepot, $nswSku, 10, '2026-09-10', location: 'MAIN');
        $this->liveOrder($this->depot, $otherSku, 10, '2026-09-10');
        $this->liveStock($this->depot, $this->sku, 20);

        $first = app(FifoAllocationService::class)->allocatePrimeStock($this->depot, $this->sku);
        $second = app(FifoAllocationService::class)->allocatePrimeStock($this->depot, $this->sku);

        $this->assertSame(4, $first->allocatedQuantity);
        $this->assertSame(0, $first->unfulfilledQuantity);
        $this->assertSame(0, $second->allocatedQuantity);
        $this->assertSame(0, $second->unfulfilledQuantity);
        $this->assertSame(4, (int) OrderAllocation::query()->sum('quantity'));
    }

    public function test_reserves_compatible_scheduled_production_after_prime_stock_without_over_reserving(): void
    {
        $this->createOrder('2026-09-01 08:00:00', '2026-09-20', 10);
        $compatibleSchedule = ProductionSchedule::factory()
            ->for($this->depot)
            ->for($this->sku, 'sku')
            ->create([
                'schedule_date' => '2026-09-18',
                'planned_quantity' => 4,
                'status' => 'scheduled',
            ]);
        ProductionSchedule::factory()
            ->for($this->depot)
            ->for($this->sku, 'sku')
            ->create([
                'schedule_date' => '2026-09-21',
                'planned_quantity' => 20,
                'status' => 'scheduled',
            ]);
        OrderAllocation::factory()
            ->for($compatibleSchedule, 'productionSchedule')
            ->create([
                'sales_order_id' => null,
                'inventory_soh_id' => null,
                'depot_id' => $this->depot->id,
                'sku_master_id' => $this->sku->id,
                'erp_entity_code' => 'RCPVIC',
                'erp_line_reference' => 'CLOSED',
                'quantity' => 1,
                'status' => 'reserved',
            ]);
        $this->liveStock($this->depot, $this->sku, 3);

        $result = app(FifoAllocationService::class)->allocateInventoryAndProduction($this->depot, $this->sku);

        $this->assertSame(6, $result->allocatedQuantity);
        $this->assertSame(4, $result->unfulfilledQuantity);
        $this->assertSame(3, OrderAllocation::query()
            ->where('erp_line_reference', '!=', 'CLOSED')
            ->whereNotNull('stock_location')
            ->sum('quantity'));
        $this->assertSame(3, OrderAllocation::query()
            ->where('erp_line_reference', '!=', 'CLOSED')
            ->where('production_schedule_id', $compatibleSchedule->id)
            ->where('status', 'reserved')
            ->sum('quantity'));
    }

    public function test_rejects_allocation_without_exactly_one_source(): void
    {
        $this->expectException(InvalidOrderAllocationException::class);

        OrderAllocation::factory()->create([
            'inventory_soh_id' => null,
            'production_schedule_id' => null,
            'stock_location' => null,
        ]);
    }

    private function createOrder(string $orderedAt, string $deliveryDate, int $quantity = 5): string
    {
        $this->liveOrder($this->depot, $this->sku, $quantity, $deliveryDate, location: '31');

        $line = $this->orderBookSource->openSalesOrderLines('RCPVIC')
            ->where('LINE_DELIVERY_DATE', $deliveryDate.' 00:00:00')
            ->orderByDesc('UNIQUE_ID')
            ->first();

        return (string) ($line->LINE_UNIQUE_ID ?? $line->UNIQUE_ID);
    }
}
