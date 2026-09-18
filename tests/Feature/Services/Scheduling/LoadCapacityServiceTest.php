<?php

namespace Tests\Feature\Services\Scheduling;

use App\Enums\ProductionQualityStatus;
use App\Exceptions\IneligibleLoadQuantityException;
use App\Exceptions\LoadCapacityExceededException;
use App\Models\Depot;
use App\Models\DispatchLoad;
use App\Models\DispatchLoadItem;
use App\Models\InventorySoh;
use App\Models\OrderAllocation;
use App\Models\ProductionActual;
use App\Models\ProductionSchedule;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use App\Services\Scheduling\LoadCapacityService;
use App\Services\Scheduling\QualityCuringService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LoadCapacityServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_calculates_item_and_load_tonnes_from_sku_unit_weight(): void
    {
        [$load, $order] = $this->loadAndOrder(12, '2.1250');

        $result = $this->service()->calculate($load, $order, 4);

        $this->assertSame('8.5000', $result->itemWeightTonnes);
        $this->assertSame('8.5000', $result->loadWeightTonnes);
        $this->assertSame('3.5000', $result->remainingCapacityTonnes);
    }

    public function test_rejects_addition_above_truck_capacity(): void
    {
        [$load, $order] = $this->loadAndOrder(10, '2.5000');

        $this->expectException(LoadCapacityExceededException::class);

        $this->service()->calculate($load, $order, 5);
    }

    public function test_adds_only_prime_stock_allocated_quantity_and_updates_load_weight(): void
    {
        [$load, $order] = $this->loadAndOrder(10, '2.5000');
        $inventory = InventorySoh::factory()
            ->for($order->depot)
            ->for($order->sku, 'sku')
            ->create();
        $allocation = OrderAllocation::factory()->for($order)->for($inventory, 'inventory')->create([
            'quantity' => 4,
        ]);

        $result = $this->service()->addItem($load, $allocation, 4);

        $this->assertSame(4, $result->item?->quantity);
        $this->assertSame($allocation->id, $result->item?->order_allocation_id);
        $this->assertSame('10.000', $load->fresh()->current_weight_tonnes);
        $this->assertSame('0.000', $result->remainingCapacityTonnes);
    }

    public function test_rejects_production_allocation_above_qc_released_good_quantity(): void
    {
        [$load, $order] = $this->loadAndOrder(20, '2.0000', '2026-09-08');
        $schedule = ProductionSchedule::factory()
            ->for($order->depot)
            ->for($order->sku, 'sku')
            ->create(['schedule_date' => '2026-09-01']);
        ProductionActual::factory()->for($schedule, 'schedule')->create([
            'manufactured_at' => '2026-09-01 00:00:00',
            'good_quantity' => 2,
            'quality_status' => ProductionQualityStatus::Released,
            'qc_released_at' => '2026-09-08 00:00:00',
            'qc_released_by' => 'quality.manager@rcpa.com.au',
        ]);
        $allocation = OrderAllocation::factory()
            ->for($order)
            ->for($schedule, 'productionSchedule')
            ->create([
                'inventory_soh_id' => null,
                'quantity' => 3,
                'status' => 'reserved',
            ]);
        $this->service()->addItem($load, $allocation, 2);

        $this->expectException(IneligibleLoadQuantityException::class);
        $this->expectExceptionMessage('Only 0 pipes');

        $this->service()->addItem($load, $allocation, 1);
    }

    public function test_rejects_quantity_above_specific_stock_allocation(): void
    {
        [$load, $order] = $this->loadAndOrder(20, '1.0000');
        $inventory = InventorySoh::factory()
            ->for($order->depot)
            ->for($order->sku, 'sku')
            ->create();
        $allocation = OrderAllocation::factory()
            ->for($order)
            ->for($inventory, 'inventory')
            ->create(['quantity' => 4]);

        $this->expectException(IneligibleLoadQuantityException::class);

        $this->service()->addItem($load, $allocation, 5);
    }

    public function test_reserved_production_is_not_dispatch_ready_while_on_quality_hold(): void
    {
        [$load, $order] = $this->loadAndOrder(20, '1.0000', '2026-09-08');
        $schedule = ProductionSchedule::factory()
            ->for($order->depot)
            ->for($order->sku, 'sku')
            ->create(['schedule_date' => '2026-09-01']);
        ProductionActual::factory()->for($schedule, 'schedule')->create([
            'manufactured_at' => '2026-09-01 00:00:00',
            'good_quantity' => 10,
            'quality_status' => ProductionQualityStatus::QualityHold,
        ]);
        $allocation = OrderAllocation::factory()
            ->for($order)
            ->for($schedule, 'productionSchedule')
            ->create([
                'inventory_soh_id' => null,
                'quantity' => 3,
                'status' => 'reserved',
            ]);

        $this->expectException(IneligibleLoadQuantityException::class);

        $this->service()->addItem($load, $allocation, 1);
    }

    public function test_dispatch_item_model_rejects_quantity_above_its_specific_allocation(): void
    {
        [$load, $order] = $this->loadAndOrder(20, '1.0000');
        $inventory = InventorySoh::factory()
            ->for($order->depot)
            ->for($order->sku, 'sku')
            ->create();
        $allocation = OrderAllocation::factory()
            ->for($order)
            ->for($inventory, 'inventory')
            ->create(['quantity' => 2]);

        $this->expectException(IneligibleLoadQuantityException::class);

        DispatchLoadItem::factory()
            ->for($load)
            ->for($allocation, 'orderAllocation')
            ->create(['quantity' => 3]);
    }

    /**
     * @return array{DispatchLoad, SalesOrder}
     */
    private function loadAndOrder(
        int $capacityTonnes,
        string $unitWeightTonnes,
        string $dispatchDate = '2026-09-20',
    ): array {
        $depot = Depot::factory()->create();
        $sku = SkuMaster::factory()->create(['unit_weight_tonnes' => $unitWeightTonnes]);
        $load = DispatchLoad::factory()->for($depot)->create([
            'capacity_tonnes' => $capacityTonnes,
            'dispatch_date' => $dispatchDate,
        ]);
        $order = SalesOrder::factory()->for($depot)->for($sku, 'sku')->create();

        return [$load, $order];
    }

    private function service(): LoadCapacityService
    {
        return new LoadCapacityService(new QualityCuringService);
    }
}
