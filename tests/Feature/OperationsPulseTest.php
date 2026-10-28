<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleLine;
use App\Models\Depot;
use App\Models\DispatchLoad;
use App\Models\DispatchLoadItem;
use App\Models\OrderAllocation;
use App\Models\ProductionActual;
use App\Models\ProductionSchedule;
use App\Models\SkuMaster;
use App\Models\User;
use App\Services\Operations\OperationsPulse;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as FilamentDashboard;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OperationsPulseTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_snapshot_counts_today_for_the_selected_plants(): void
    {
        $day = CarbonImmutable::parse('2026-11-02', 'Australia/Melbourne');
        $plant = Depot::factory()->create();
        $other = Depot::factory()->create();
        $sku = SkuMaster::factory()->create(['unit_weight_tonnes' => 1]);

        $run = ProductionSchedule::factory()->create([
            'depot_id' => $plant->id,
            'sku_master_id' => $sku->id,
            'schedule_date' => $day->toDateString(),
            'planned_quantity' => 10,
            'status' => 'scheduled',
        ]);
        ProductionSchedule::factory()->create([
            'depot_id' => $plant->id,
            'schedule_date' => $day->toDateString(),
            'planned_quantity' => 50,
            'status' => 'draft',
        ]);
        ProductionSchedule::factory()->create([
            'depot_id' => $other->id,
            'schedule_date' => $day->toDateString(),
            'planned_quantity' => 99,
            'status' => 'scheduled',
        ]);
        ProductionActual::factory()->create([
            'production_schedule_id' => $run->id,
            'manufactured_at' => $day->setTime(10, 0),
            'good_quantity' => 6,
            'dumped_quantity' => 2,
            'dump_reason' => 'crack',
        ]);

        $schedule = DeliverySchedule::query()->create([
            'depot_id' => $plant->id,
            'schedule_date' => $day->toDateString(),
            'status' => 'draft',
        ]);
        DeliveryScheduleLine::query()->create([
            'delivery_schedule_id' => $schedule->id,
            'erp_entity_code' => 'RCPQLD',
            'erp_line_reference' => '1',
            'dispatch_qty' => 4,
            'tonnage' => 1.5,
            'is_confirmed' => true,
        ]);
        DeliveryScheduleLine::query()->create([
            'delivery_schedule_id' => $schedule->id,
            'erp_entity_code' => 'RCPQLD',
            'erp_line_reference' => '2',
            'dispatch_qty' => 8,
            'tonnage' => 3,
            'is_confirmed' => false,
        ]);
        $allocation = OrderAllocation::factory()->create([
            'sales_order_id' => null,
            'inventory_soh_id' => null,
            'production_schedule_id' => null,
            'depot_id' => $plant->id,
            'sku_master_id' => $sku->id,
            'stock_location' => '31',
            'quantity' => 3,
            'status' => 'allocated',
            'erp_entity_code' => 'RCPQLD',
            'erp_line_reference' => '1',
        ]);
        $load = DispatchLoad::factory()->create([
            'depot_id' => $plant->id,
            'dispatch_date' => $day->toDateString(),
            'capacity_tonnes' => 24,
            'current_weight_tonnes' => 4,
            'status' => 'planned',
        ]);
        DispatchLoadItem::query()->create([
            'dispatch_load_id' => $load->id,
            'order_allocation_id' => $allocation->id,
            'quantity' => 3,
            'weight_tonnes' => 4,
        ]);
        DispatchLoad::factory()->create([
            'depot_id' => $other->id,
            'dispatch_date' => $day->toDateString(),
            'capacity_tonnes' => 24,
            'current_weight_tonnes' => 20,
        ]);

        $pulse = app(OperationsPulse::class)->snapshot([$plant->id], $day);

        $this->assertSame(10, $pulse['planned_quantity']);
        $this->assertSame(1, $pulse['runs']);
        $this->assertSame(6, $pulse['good_quantity']);
        $this->assertSame(2, $pulse['dumped_quantity']);
        $this->assertSame(2, $pulse['schedule_lines']);
        $this->assertSame(1, $pulse['confirmed_lines']);
        $this->assertSame(1, $pulse['unconfirmed_lines']);
        $this->assertSame(1.5, $pulse['confirmed_tonnes']);
        $this->assertSame(1, $pulse['loads']);
        $this->assertSame(4.0, $pulse['load_tonnes']);
        $this->assertSame(24.0, $pulse['load_capacity_tonnes']);
        $this->assertSame(3, $pulse['loaded_quantity']);
    }

    public function test_planners_see_every_plant_and_dispatchers_see_their_own(): void
    {
        $plant = Depot::factory()->create();
        $planner = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();
        $dispatcher = User::factory()->withAppRoles(AppRole::LogisticsDispatcher)->create();
        $dispatcher->depots()->attach($plant);

        $pulse = app(OperationsPulse::class);

        $this->assertNull($pulse->depotIdsFor($planner));
        $this->assertSame([$plant->id], $pulse->depotIdsFor($dispatcher));
    }

    public function test_dashboard_shows_production_and_despatch(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();

        $this->actingAs($user)
            ->get(FilamentDashboard::getUrl())
            ->assertSuccessful()
            ->assertSee('Production')
            ->assertSee('Despatch')
            ->assertSee('Planned pipe')
            ->assertSee('Schedule lines');
    }
}
