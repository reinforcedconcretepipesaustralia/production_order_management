<?php

namespace Tests\Feature;

use App\Exceptions\IneligibleLoadQuantityException;
use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleLine;
use App\Models\Depot;
use App\Models\DispatchLoad;
use App\Models\OrderAllocation;
use App\Models\SkuMaster;
use App\Services\Logistics\ScheduleLineDispatchService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ScheduleLineDispatchServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_confirmed_line_is_added_to_the_matching_load(): void
    {
        [$line, $load] = $this->lineAndLoad(confirmed: true);

        app(ScheduleLineDispatchService::class)->addToLoad($line, $load);

        $this->assertDatabaseHas('dispatch_load_items', [
            'dispatch_load_id' => $load->id,
            'order_allocation_id' => $line->order_allocation_id,
            'quantity' => 4,
        ]);
    }

    public function test_unconfirmed_line_is_rejected(): void
    {
        [$line, $load] = $this->lineAndLoad(confirmed: false);

        $this->expectException(IneligibleLoadQuantityException::class);

        app(ScheduleLineDispatchService::class)->addToLoad($line, $load);
    }

    /**
     * @return array{0: DeliveryScheduleLine, 1: DispatchLoad}
     */
    private function lineAndLoad(bool $confirmed): array
    {
        $depot = Depot::factory()->create();
        $sku = SkuMaster::factory()->create(['unit_weight_tonnes' => 1]);
        $allocation = OrderAllocation::factory()->create([
            'sales_order_id' => null,
            'inventory_soh_id' => null,
            'production_schedule_id' => null,
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
            'erp_entity_code' => 'RCPQLD',
            'erp_line_reference' => '9001',
            'stock_location' => '40',
            'quantity' => 4,
            'status' => 'allocated',
        ]);
        $schedule = DeliverySchedule::query()->create([
            'depot_id' => $depot->id,
            'schedule_date' => '2026-11-02',
            'status' => 'draft',
        ]);
        $line = DeliveryScheduleLine::query()->create([
            'delivery_schedule_id' => $schedule->id,
            'erp_entity_code' => 'RCPQLD',
            'erp_line_reference' => '9001',
            'order_allocation_id' => $allocation->id,
            'dispatch_qty' => 4,
            'tonnage' => 4,
            'is_confirmed' => $confirmed,
        ]);
        $load = DispatchLoad::factory()->create([
            'depot_id' => $depot->id,
            'dispatch_date' => '2026-11-02',
            'capacity_tonnes' => 24,
        ]);

        return [$line, $load];
    }
}
