<?php

namespace Tests\Feature;

use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleLine;
use App\Models\Depot;
use App\Models\DispatchLoad;
use App\Models\DispatchLoadItem;
use App\Models\OrderAllocation;
use App\Models\SkuMaster;
use App\Services\Logistics\DispatchHorizon;
use App\Services\Sync\OrderSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Fixtures\SeedsLiveOrderBook;
use Tests\TestCase;

class DispatchHorizonTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SeedsLiveOrderBook;

    public function test_due_order_appears_on_its_delivery_date(): void
    {
        $this->installLiveOrderBook();
        $depot = Depot::factory()->create([
            'erp_entity_code' => 'RCPVIC',
            'prime_stock_locations' => ['31'],
            'name' => 'Yatala',
        ]);
        $sku = SkuMaster::factory()->create([
            'erp_entity_code' => 'RCPVIC',
            'unit_weight_tonnes' => 0.5,
        ]);
        $this->liveOrder($depot, $sku, 4, '2026-11-02', location: '31');

        $day = app(DispatchHorizon::class)->between(
            CarbonImmutable::parse('2026-11-01'),
            CarbonImmutable::parse('2026-11-07'),
            [$depot->id],
        )->get('2026-11-02');

        $this->assertNotNull($day);
        $this->assertSame(4, $day['qty']);
        $this->assertSame(2.0, $day['tonnes']);
        $this->assertSame('due', $day['lines'][0]['status']);
        $this->assertSame('Yatala', $day['lines'][0]['depot']);
    }

    public function test_confirmed_schedule_line_marks_the_day_confirmed(): void
    {
        $this->installLiveOrderBook();
        $depot = Depot::factory()->create([
            'erp_entity_code' => 'RCPVIC',
            'prime_stock_locations' => ['31'],
        ]);
        $sku = SkuMaster::factory()->create(['erp_entity_code' => 'RCPVIC']);
        $this->liveOrder($depot, $sku, 4, '2026-11-02', location: '31');
        $order = app(OrderSync::class)->lines()->first();
        $schedule = DeliverySchedule::query()->create([
            'depot_id' => $depot->id,
            'schedule_date' => '2026-11-02',
            'status' => 'draft',
        ]);
        DeliveryScheduleLine::query()->create([
            'delivery_schedule_id' => $schedule->id,
            'erp_entity_code' => $order['erp_entity_code'],
            'erp_line_reference' => $order['erp_line_reference'],
            'erp_order_reference' => 'SO-44',
            'customer_name' => 'Acme',
            'stock_code' => $sku->sku_code,
            'dispatch_qty' => 4,
            'tonnage' => 2,
            'is_confirmed' => true,
        ]);

        $day = app(DispatchHorizon::class)->between(
            CarbonImmutable::parse('2026-11-02'),
            CarbonImmutable::parse('2026-11-02'),
            [$depot->id],
        )->get('2026-11-02');

        $this->assertSame(1, $day['confirmed']);
        $this->assertSame('confirmed', $day['lines'][0]['status']);
        $this->assertSame('Acme', $day['lines'][0]['customer']);
        $this->assertSame('SO-44', $day['lines'][0]['reference']);
    }

    public function test_line_on_a_load_for_that_day_is_loaded(): void
    {
        $this->installLiveOrderBook();
        $depot = Depot::factory()->create([
            'erp_entity_code' => 'RCPVIC',
            'prime_stock_locations' => ['31'],
        ]);
        $sku = SkuMaster::factory()->create([
            'erp_entity_code' => 'RCPVIC',
            'unit_weight_tonnes' => 1,
        ]);
        $this->liveOrder($depot, $sku, 4, '2026-11-02', location: '31');
        $order = app(OrderSync::class)->lines()->first();
        $allocation = OrderAllocation::factory()->create([
            'sales_order_id' => null,
            'inventory_soh_id' => null,
            'production_schedule_id' => null,
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
            'erp_entity_code' => $order['erp_entity_code'],
            'erp_line_reference' => $order['erp_line_reference'],
            'stock_location' => '31',
            'quantity' => 4,
            'status' => 'allocated',
        ]);
        $load = DispatchLoad::factory()->create([
            'depot_id' => $depot->id,
            'dispatch_date' => '2026-11-02',
            'current_weight_tonnes' => 4,
        ]);
        DispatchLoadItem::query()->create([
            'dispatch_load_id' => $load->id,
            'order_allocation_id' => $allocation->id,
            'quantity' => 4,
            'weight_tonnes' => 4,
        ]);
        $schedule = DeliverySchedule::query()->create([
            'depot_id' => $depot->id,
            'schedule_date' => '2026-11-02',
            'status' => 'published',
        ]);
        DeliveryScheduleLine::query()->create([
            'delivery_schedule_id' => $schedule->id,
            'erp_entity_code' => $order['erp_entity_code'],
            'erp_line_reference' => $order['erp_line_reference'],
            'order_allocation_id' => $allocation->id,
            'dispatch_qty' => 4,
            'tonnage' => 4,
            'is_confirmed' => true,
        ]);

        $day = app(DispatchHorizon::class)->between(
            CarbonImmutable::parse('2026-11-02'),
            CarbonImmutable::parse('2026-11-02'),
            [$depot->id],
        )->get('2026-11-02');

        $this->assertSame('loaded', $day['lines'][0]['status']);
        $this->assertSame(1, $day['loads']);
        $this->assertSame(4.0, $day['load_tonnes']);
    }
}
