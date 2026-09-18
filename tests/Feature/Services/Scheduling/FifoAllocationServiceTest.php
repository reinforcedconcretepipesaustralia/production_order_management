<?php

namespace Tests\Feature\Services\Scheduling;

use App\Exceptions\InvalidOrderAllocationException;
use App\Models\Depot;
use App\Models\InventorySoh;
use App\Models\OrderAllocation;
use App\Models\ProductionSchedule;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use App\Services\Scheduling\FifoAllocationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FifoAllocationServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_allocates_only_prime_stock_in_order_and_delivery_fifo_order(): void
    {
        $depot = Depot::factory()->create();
        $sku = SkuMaster::factory()->create();
        $oldest = $this->createOrder($depot, $sku, '2026-09-01 08:00:00', '2026-09-20');
        $laterDelivery = $this->createOrder($depot, $sku, '2026-09-02 08:00:00', '2026-09-25');
        $earlierDelivery = $this->createOrder($depot, $sku, '2026-09-02 08:00:00', '2026-09-22');
        $inventory = InventorySoh::factory()->for($depot)->for($sku, 'sku')->create([
            'prime_quantity' => 12,
            'rework_quantity' => 100,
            'quarantine_quantity' => 100,
        ]);

        $result = (new FifoAllocationService)->allocatePrimeStock($depot, $sku);

        $this->assertSame(12, $result->allocatedQuantity);
        $this->assertSame(3, $result->unfulfilledQuantity);
        $this->assertSame(0, $inventory->fresh()->prime_quantity);
        $this->assertSame(100, $inventory->fresh()->rework_quantity);
        $this->assertSame(100, $inventory->fresh()->quarantine_quantity);
        $this->assertSame(5, $oldest->fresh()->allocated_quantity);
        $this->assertSame(2, $laterDelivery->fresh()->allocated_quantity);
        $this->assertSame(5, $earlierDelivery->fresh()->allocated_quantity);
        $this->assertSame(
            [$oldest->id, $earlierDelivery->id, $laterDelivery->id],
            OrderAllocation::query()->orderBy('id')->pluck('sales_order_id')->all(),
        );
        $this->assertSame(
            [$inventory->id, $inventory->id, $inventory->id],
            OrderAllocation::query()->orderBy('id')->pluck('inventory_soh_id')->all(),
        );
    }

    public function test_never_allocates_other_depot_or_sku_and_cannot_over_allocate_on_repeat(): void
    {
        $depot = Depot::factory()->create();
        $otherDepot = Depot::factory()->create();
        $sku = SkuMaster::factory()->create();
        $otherSku = SkuMaster::factory()->create();
        $order = $this->createOrder($depot, $sku, '2026-09-01 08:00:00', '2026-09-20', 4);
        $this->createOrder($otherDepot, $sku, '2026-08-01 08:00:00', '2026-09-10', 10);
        $this->createOrder($depot, $otherSku, '2026-08-01 08:00:00', '2026-09-10', 10);
        InventorySoh::factory()->for($depot)->for($sku, 'sku')->create(['prime_quantity' => 20]);

        $first = (new FifoAllocationService)->allocatePrimeStock($depot, $sku);
        $second = (new FifoAllocationService)->allocatePrimeStock($depot, $sku);

        $this->assertSame(4, $first->allocatedQuantity);
        $this->assertSame(0, $first->unfulfilledQuantity);
        $this->assertSame(0, $second->allocatedQuantity);
        $this->assertSame(0, $second->unfulfilledQuantity);
        $this->assertSame(4, $order->fresh()->allocated_quantity);
        $this->assertSame(4, OrderAllocation::query()->sum('quantity'));
    }

    public function test_reserves_compatible_scheduled_production_after_prime_stock_without_over_reserving(): void
    {
        $depot = Depot::factory()->create();
        $sku = SkuMaster::factory()->create();
        $closedOrder = $this->createOrder($depot, $sku, '2026-08-01 08:00:00', '2026-09-15', 1);
        $closedOrder->update(['allocated_quantity' => 1, 'status' => 'allocated']);
        $targetOrder = $this->createOrder($depot, $sku, '2026-09-01 08:00:00', '2026-09-20', 10);
        $compatibleSchedule = ProductionSchedule::factory()
            ->for($depot)
            ->for($sku, 'sku')
            ->create([
                'schedule_date' => '2026-09-18',
                'planned_quantity' => 4,
                'status' => 'planned',
            ]);
        ProductionSchedule::factory()
            ->for($depot)
            ->for($sku, 'sku')
            ->create([
                'schedule_date' => '2026-09-21',
                'planned_quantity' => 20,
                'status' => 'planned',
            ]);
        OrderAllocation::factory()
            ->for($closedOrder)
            ->for($compatibleSchedule, 'productionSchedule')
            ->create([
                'inventory_soh_id' => null,
                'quantity' => 1,
                'status' => 'reserved',
            ]);
        $inventory = InventorySoh::factory()->for($depot)->for($sku, 'sku')->create(['prime_quantity' => 3]);

        $result = (new FifoAllocationService)->allocateInventoryAndProduction($depot, $sku);

        $this->assertSame(6, $result->allocatedQuantity);
        $this->assertSame(4, $result->unfulfilledQuantity);
        $this->assertSame(6, $targetOrder->fresh()->allocated_quantity);
        $this->assertSame(3, OrderAllocation::query()
            ->where('sales_order_id', $targetOrder->id)
            ->where('inventory_soh_id', $inventory->id)
            ->sum('quantity'));
        $this->assertSame(3, OrderAllocation::query()
            ->where('sales_order_id', $targetOrder->id)
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
        ]);
    }

    private function createOrder(
        Depot $depot,
        SkuMaster $sku,
        string $orderedAt,
        string $deliveryDate,
        int $quantity = 5,
    ): SalesOrder {
        return SalesOrder::factory()->for($depot)->for($sku, 'sku')->create([
            'ordered_quantity' => $quantity,
            'ordered_at' => $orderedAt,
            'requested_delivery_date' => $deliveryDate,
        ]);
    }
}
