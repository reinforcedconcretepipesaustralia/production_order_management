<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Filament\Pages\DemandVsProdPlan;
use App\Filament\Pages\LogisticsLoadBuilder;
use App\Filament\Pages\ProductionBoard;
use App\Filament\Pages\SteelRequisitionDashboard;
use App\Models\Depot;
use App\Models\DispatchLoad;
use App\Models\InventorySoh;
use App\Models\MachineAsset;
use App\Models\OrderAllocation;
use App\Models\ProductionSchedule;
use App\Models\SalesOrder;
use App\Models\Shift;
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
            'demand vs prod plan' => [DemandVsProdPlan::class],
            'steel forecast' => [SteelRequisitionDashboard::class],
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
        $this->get(DemandVsProdPlan::getUrl())
            ->assertOk()
            ->assertSee('Demand vs Prod Plan')
            ->assertSee('Variance is plan minus demand')
            ->assertDontSee('RCPA_SQL_PASSWORD');
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
            ->assertSee('Plant')
            ->assertSee('Shift')
            ->assertSee('Machines')
            ->assertSee('VISIBLE-SKU')
            ->assertDontSee('HIDDEN-SKU')
            ->set('status', 'draft')
            ->assertSee('No production schedules found');
    }

    public function test_production_board_applies_plant_shift_and_machine_filters(): void
    {
        $plant = Depot::factory()->create(['code' => 'PLANT-A']);
        $planner = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();
        $morning = Shift::factory()->create([
            'depot_id' => $plant->id,
            'name' => 'Morning',
            'is_active' => true,
        ]);
        $afternoon = Shift::factory()->create([
            'depot_id' => $plant->id,
            'name' => 'Afternoon',
            'is_active' => true,
        ]);
        $machineA = MachineAsset::factory()->create([
            'depot_id' => $plant->id,
            'name' => 'BiDi 36',
            'is_active' => true,
        ]);
        $machineB = MachineAsset::factory()->create([
            'depot_id' => $plant->id,
            'name' => 'BiDi 84',
            'is_active' => true,
        ]);
        $skuMorning = SkuMaster::factory()->create(['sku_code' => 'MORNING-SKU']);
        $skuAfternoon = SkuMaster::factory()->create(['sku_code' => 'AFTERNOON-SKU']);
        $skuOtherMachine = SkuMaster::factory()->create(['sku_code' => 'OTHER-MACHINE-SKU']);

        ProductionSchedule::factory()->create([
            'depot_id' => $plant->id,
            'shift_id' => $morning->id,
            'machine_asset_id' => $machineA->id,
            'sku_master_id' => $skuMorning->id,
            'schedule_date' => now()->toDateString(),
            'planned_start_at' => now()->startOfDay()->addHours(6),
            'planned_end_at' => now()->startOfDay()->addHours(14),
            'status' => 'scheduled',
        ]);
        ProductionSchedule::factory()->create([
            'depot_id' => $plant->id,
            'shift_id' => $afternoon->id,
            'machine_asset_id' => $machineA->id,
            'sku_master_id' => $skuAfternoon->id,
            'schedule_date' => now()->toDateString(),
            'planned_start_at' => now()->startOfDay()->addHours(14),
            'planned_end_at' => now()->startOfDay()->addHours(22),
            'status' => 'scheduled',
        ]);
        ProductionSchedule::factory()->create([
            'depot_id' => $plant->id,
            'shift_id' => $morning->id,
            'machine_asset_id' => $machineB->id,
            'sku_master_id' => $skuOtherMachine->id,
            'schedule_date' => now()->toDateString(),
            'planned_start_at' => now()->startOfDay()->addHours(6),
            'planned_end_at' => now()->startOfDay()->addHours(14),
            'status' => 'scheduled',
        ]);

        Livewire::actingAs($planner)
            ->test(ProductionBoard::class)
            ->set('depotId', $plant->id)
            ->assertSee('MORNING-SKU')
            ->assertSee('AFTERNOON-SKU')
            ->assertSee('OTHER-MACHINE-SKU')
            ->set('shiftName', 'Morning')
            ->assertSee('MORNING-SKU')
            ->assertDontSee('AFTERNOON-SKU')
            ->assertSee('OTHER-MACHINE-SKU')
            ->set('machineName', 'BiDi 36')
            ->assertSee('MORNING-SKU')
            ->assertDontSee('AFTERNOON-SKU')
            ->assertDontSee('OTHER-MACHINE-SKU');
    }

    public function test_operational_filter_dropdowns_list_distinct_shift_and_machine_names(): void
    {
        $melton = Depot::factory()->create(['code' => 'MEL', 'name' => 'Melton']);
        $yatala = Depot::factory()->create(['code' => 'YAT', 'name' => 'Yatala']);
        $planner = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();

        Shift::factory()->create(['depot_id' => $melton->id, 'name' => 'Day Shift', 'is_active' => true]);
        Shift::factory()->create(['depot_id' => $yatala->id, 'name' => 'Day Shift', 'is_active' => true]);
        Shift::factory()->create(['depot_id' => $melton->id, 'name' => 'Afternoon Shift', 'is_active' => true]);
        MachineAsset::factory()->create(['depot_id' => $melton->id, 'name' => 'BiDi 36', 'is_active' => true]);
        MachineAsset::factory()->create(['depot_id' => $yatala->id, 'name' => 'BiDi 36', 'is_active' => true]);
        MachineAsset::factory()->create(['depot_id' => $melton->id, 'name' => 'BiDi 84', 'is_active' => true]);

        Livewire::actingAs($planner)
            ->test(ProductionBoard::class)
            ->assertSeeHtml('>Day Shift</option>')
            ->assertSeeHtml('>Afternoon Shift</option>')
            ->assertSeeHtml('>BiDi 36</option>')
            ->assertSeeHtml('>BiDi 84</option>');

        $html = Livewire::actingAs($planner)->test(ProductionBoard::class)->html();

        $this->assertSame(1, substr_count($html, '>Day Shift</option>'));
        $this->assertSame(1, substr_count($html, '>BiDi 36</option>'));
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
        $this->travelTo('2026-11-11 09:00:00');

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
            ->assertSee('DATE FROM')
            ->assertSee('DATE TO')
            ->assertSee('WIRE-A')
            ->assertSee('Planned new order quantity')
            ->assertSee('3')
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
