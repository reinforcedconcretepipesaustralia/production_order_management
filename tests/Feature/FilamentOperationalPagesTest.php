<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Filament\Pages\AlternateProductionBoard;
use App\Filament\Pages\LogisticsLoadBuilder;
use App\Filament\Pages\ProductionBoard;
use App\Filament\Pages\SteelRequisitionDashboard;
use App\Filament\Widgets\ProductionScheduleCalendar;
use App\Models\Depot;
use App\Models\DispatchLoad;
use App\Models\InventorySoh;
use App\Models\OrderAllocation;
use App\Models\ProductionSchedule;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use App\Models\SkuWireRequirement;
use App\Models\SteelForecast;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FilamentOperationalPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function operationalPages(): array
    {
        return [
            'production board' => [ProductionBoard::class],
            'Guava production board' => [AlternateProductionBoard::class],
            'steel requisitions' => [SteelRequisitionDashboard::class],
            'load builder' => [LogisticsLoadBuilder::class],
        ];
    }

    #[DataProvider('operationalPages')]
    public function test_operational_pages_require_authentication(string $page): void
    {
        $this->get($page::getUrl())->assertRedirect();
    }

    #[DataProvider('operationalPages')]
    public function test_operational_pages_forbid_users_without_a_relevant_role(string $page): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get($page::getUrl())->assertForbidden();
    }

    public function test_executive_can_view_all_operational_pages_without_mutation_controls(): void
    {
        $executive = User::factory()->withAppRoles(AppRole::ViewerExecutive)->create();

        $this->actingAs($executive);

        $this->get(ProductionBoard::getUrl())
            ->assertOk()
            ->assertSee('ERP production demand')
            ->assertSee('ERP SKU master')
            ->assertDontSee('RCPA_SQL_PASSWORD');
        $this->get(AlternateProductionBoard::getUrl())
            ->assertOk()
            ->assertSee('Guava schedule timeline')
            ->assertSee('view-only while we compare both approaches');
        $this->get(SteelRequisitionDashboard::getUrl())
            ->assertOk()
            ->assertSee('ERP stock on hand')
            ->assertSee('ERP BOM lines')
            ->assertDontSee('Calculate forecasts')
            ->assertDontSee('RCPA_SQL_PASSWORD');
        $this->get(LogisticsLoadBuilder::getUrl())
            ->assertOk()
            ->assertSee('ERP sales orders')
            ->assertDontSee('Create dispatch load')
            ->assertDontSee('Add eligible allocation')
            ->assertDontSee('RCPA_SQL_PASSWORD');
    }

    public function test_production_board_scopes_data_and_applies_status_filter(): void
    {
        $assignedDepot = Depot::factory()->create(['code' => 'ASSIGNED']);
        $otherDepot = Depot::factory()->create(['code' => 'OTHER']);
        $operator = User::factory()->withAppRoles(AppRole::DepotOperator)->create();
        $operator->depots()->attach($assignedDepot);

        $visibleSku = SkuMaster::factory()->create(['sku_code' => 'VISIBLE-SKU']);
        $hiddenSku = SkuMaster::factory()->create(['sku_code' => 'HIDDEN-SKU']);
        ProductionSchedule::factory()->create([
            'depot_id' => $assignedDepot->id,
            'sku_master_id' => $visibleSku->id,
            'schedule_date' => now()->toDateString(),
            'planned_start_at' => now()->startOfDay()->addHours(6),
            'planned_end_at' => now()->startOfDay()->addHours(14),
            'status' => 'scheduled',
        ]);
        ProductionSchedule::factory()->create([
            'depot_id' => $otherDepot->id,
            'sku_master_id' => $hiddenSku->id,
            'schedule_date' => now()->toDateString(),
            'planned_start_at' => now()->startOfDay()->addHours(6),
            'planned_end_at' => now()->startOfDay()->addHours(14),
            'status' => 'draft',
        ]);

        Livewire::actingAs($operator)
            ->test(ProductionBoard::class)
            ->assertSee('VISIBLE-SKU')
            ->assertDontSee('HIDDEN-SKU')
            ->set('status', 'draft')
            ->assertSee('No production schedules found');
    }

    public function test_guava_board_maps_only_accessible_schedules_and_machines(): void
    {
        $assignedDepot = Depot::factory()->create(['code' => 'ASSIGNED']);
        $otherDepot = Depot::factory()->create(['code' => 'OTHER']);
        $operator = User::factory()->withAppRoles(AppRole::DepotOperator)->create();
        $operator->depots()->attach($assignedDepot);
        $visibleSku = SkuMaster::factory()->create(['sku_code' => 'VISIBLE-SKU']);
        $hiddenSku = SkuMaster::factory()->create(['sku_code' => 'HIDDEN-SKU']);
        $start = CarbonImmutable::parse('2026-09-21 06:00:00');

        $visibleSchedule = ProductionSchedule::factory()->create([
            'depot_id' => $assignedDepot->id,
            'sku_master_id' => $visibleSku->id,
            'planned_start_at' => $start,
            'planned_end_at' => $start->addHours(8),
            'planned_quantity' => 42,
            'planned_oee_percent' => 85,
            'status' => 'scheduled',
        ]);
        ProductionSchedule::factory()->create([
            'depot_id' => $otherDepot->id,
            'sku_master_id' => $hiddenSku->id,
            'planned_start_at' => $start,
            'planned_end_at' => $start->addHours(8),
            'status' => 'scheduled',
        ]);

        $this->actingAs($operator);

        $calendar = app(ProductionScheduleCalendar::class);
        $resources = $calendar->getResourcesJs();
        $events = $calendar->getEventsJs([
            'startStr' => $start->startOfWeek()->toIso8601String(),
            'endStr' => $start->endOfWeek()->addSecond()->toIso8601String(),
            'tzOffset' => 0,
        ]);

        $this->assertCount(1, $resources);
        $this->assertSame($visibleSchedule->machine_asset_id, $resources[0]['id']);
        $this->assertStringStartsWith('ASSIGNED — ', $resources[0]['title']);
        $this->assertCount(1, $events);
        $this->assertSame('VISIBLE-SKU · 42 pipes · OEE 85% · Scheduled', $events[0]['title']);
        $this->assertSame([$visibleSchedule->machine_asset_id], $events[0]['resourceIds']);
        $this->assertSame('#002F67', $events[0]['backgroundColor']);
        $this->assertFalse($events[0]['startEditable']);
        $this->assertFalse($events[0]['durationEditable']);
    }

    /**
     * Regression: schedule_date is a date cast, so it persists a midnight time. Compared against a
     * bare 'Y-m-d' string the last day of the window sorts after it and vanishes from the board —
     * which used to hide every Sunday's work whenever the week ended on a Sunday.
     */
    public function test_the_board_shows_work_on_the_last_day_of_its_window(): void
    {
        $depot = Depot::factory()->create(['code' => 'EDGE']);
        $planner = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();
        $sku = SkuMaster::factory()->create(['sku_code' => 'LAST-DAY-SKU']);

        $lastDay = CarbonImmutable::parse(now()->endOfWeek()->toDateString());

        ProductionSchedule::factory()->create([
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
            'schedule_date' => $lastDay->toDateString(),
            'planned_start_at' => $lastDay->addHours(6),
            'planned_end_at' => $lastDay->addHours(14),
            'status' => 'scheduled',
        ]);

        Livewire::actingAs($planner)
            ->test(ProductionBoard::class)
            ->assertSee('LAST-DAY-SKU');
    }

    public function test_materials_buyer_sees_assigned_forecasts_and_can_recalculate(): void
    {
        $assignedDepot = Depot::factory()->create(['code' => 'BUYER']);
        $otherDepot = Depot::factory()->create(['code' => 'HIDDEN']);
        $buyer = User::factory()->withAppRoles(AppRole::MaterialsBuyer)->create();
        $buyer->depots()->attach($assignedDepot);
        $sku = SkuMaster::factory()->create([
            'sku_code' => 'PIPE-WIRE-A',
            'wire_code' => 'WIRE-A',
            'wire_kg_per_pipe' => 100,
        ]);
        // A pipe's steel lives in sku_wire_requirements now, one row per gauge it consumes.
        SkuWireRequirement::factory()->create([
            'sku_master_id' => $sku->id, 'wire_code' => 'WIRE-A', 'kg_per_pipe' => 100,
        ]);
        ProductionSchedule::factory()->create([
            'depot_id' => $assignedDepot->id,
            'sku_master_id' => $sku->id,
            'schedule_date' => now()->toDateString(),
            'planned_start_at' => now()->startOfDay()->addHours(6),
            'planned_end_at' => now()->startOfDay()->addHours(14),
            'planned_quantity' => 20,
            'status' => 'scheduled',
        ]);
        SteelForecast::factory()->create([
            'depot_id' => $assignedDepot->id,
            'forecast_date' => now()->toDateString(),
            'wire_code' => 'WIRE-A',
            'on_hand_coils' => 0,
            'on_order_coils' => 0,
            'average_coil_weight_tonnes' => 1,
            'safety_threshold_coils' => 1,
        ]);
        SteelForecast::factory()->create([
            'depot_id' => $otherDepot->id,
            'wire_code' => 'WIRE-HIDDEN',
        ]);

        $component = Livewire::actingAs($buyer)
            ->test(SteelRequisitionDashboard::class)
            ->assertSee('WIRE-A')
            ->assertSee('3 coils recommended')
            ->assertDontSee('WIRE-HIDDEN')
            ->call('calculateForecasts')
            ->assertHasNoErrors();

        $this->assertNotNull($component->get('calculatedAt'));
    }

    public function test_logistics_dispatcher_creates_load_and_adds_depot_allocation(): void
    {
        $depot = Depot::factory()->create(['code' => 'LOG']);
        $dispatcher = User::factory()->withAppRoles(AppRole::LogisticsDispatcher)->create();
        $dispatcher->depots()->attach($depot);
        $sku = SkuMaster::factory()->create(['unit_weight_tonnes' => 1.5]);
        $order = SalesOrder::factory()->create([
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
            'erp_order_reference' => 'SO-LOAD',
        ]);
        $inventory = InventorySoh::factory()->create([
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
        ]);
        $allocation = OrderAllocation::factory()->create([
            'sales_order_id' => $order->id,
            'inventory_soh_id' => $inventory->id,
            'quantity' => 5,
        ]);

        $component = Livewire::actingAs($dispatcher)
            ->test(LogisticsLoadBuilder::class)
            ->set('depotId', $depot->id)
            ->set('loadReference', 'LOAD-TEST-001')
            ->set('dispatchDate', now()->addDay()->toDateString())
            ->set('capacityTonnes', '10')
            ->call('createLoad')
            ->assertHasNoErrors();

        $load = DispatchLoad::query()->where('load_reference', 'LOAD-TEST-001')->firstOrFail();

        $component
            ->assertSet('dispatchLoadId', $load->id)
            ->set('allocationId', $allocation->id)
            ->set('quantity', 2)
            ->call('addAllocation')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('dispatch_load_items', [
            'dispatch_load_id' => $load->id,
            'order_allocation_id' => $allocation->id,
            'quantity' => 2,
            'weight_tonnes' => 3,
        ]);
        $this->assertSame('3.000', $load->fresh()->current_weight_tonnes);
    }

    public function test_load_builder_rejects_cross_depot_idor_and_capacity_conflicts(): void
    {
        $assignedDepot = Depot::factory()->create(['code' => 'ASSIGNED']);
        $otherDepot = Depot::factory()->create(['code' => 'OTHER']);
        $dispatcher = User::factory()->withAppRoles(AppRole::LogisticsDispatcher)->create();
        $dispatcher->depots()->attach($assignedDepot);
        $load = DispatchLoad::factory()->create([
            'depot_id' => $assignedDepot->id,
            'capacity_tonnes' => 1,
        ]);
        $heavySku = SkuMaster::factory()->create(['unit_weight_tonnes' => 1.5]);
        $assignedAllocation = $this->inventoryAllocation($assignedDepot, $heavySku);
        $otherAllocation = $this->inventoryAllocation($otherDepot, $heavySku);

        Livewire::actingAs($dispatcher)
            ->test(LogisticsLoadBuilder::class)
            ->set('dispatchLoadId', $load->id)
            ->set('allocationId', $otherAllocation->id)
            ->set('quantity', 1)
            ->call('addAllocation')
            ->assertNotFound();

        Livewire::actingAs($dispatcher)
            ->test(LogisticsLoadBuilder::class)
            ->set('dispatchLoadId', $load->id)
            ->set('allocationId', $assignedAllocation->id)
            ->set('quantity', 1)
            ->call('addAllocation')
            ->assertHasErrors(['quantity']);

        $this->assertDatabaseCount('dispatch_load_items', 0);
    }

    private function inventoryAllocation(Depot $depot, SkuMaster $sku): OrderAllocation
    {
        $order = SalesOrder::factory()->create([
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
        ]);
        $inventory = InventorySoh::factory()->create([
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
        ]);

        return OrderAllocation::factory()->create([
            'sales_order_id' => $order->id,
            'inventory_soh_id' => $inventory->id,
            'quantity' => 5,
        ]);
    }
}
