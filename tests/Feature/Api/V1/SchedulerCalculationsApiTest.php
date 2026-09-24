<?php

namespace Tests\Feature\Api\V1;

use App\Enums\AppRole;
use App\Exceptions\InvalidOrderAllocationException;
use App\Models\Depot;
use App\Models\MachineAsset;
use App\Models\MouldAsset;
use App\Models\OrderAllocation;
use App\Models\ProductionSchedule;
use App\Models\Shift;
use App\Models\SkuMaster;
use App\Models\SkuWireRequirement;
use App\Models\SteelForecast;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SeedsLiveOrderBook;
use Tests\TestCase;

class SchedulerCalculationsApiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SeedsLiveOrderBook;

    #[DataProvider('endpointRoutes')]
    public function test_requires_authentication_for_every_endpoint(string $routeName): void
    {
        $this->postJson(route($routeName))
            ->assertUnauthorized();
    }

    #[DataProvider('endpointRoutes')]
    public function test_forbids_users_without_a_supported_capability(string $routeName): void
    {
        $depot = Depot::factory()->create();
        $user = User::factory()->withAppRoles(AppRole::ViewerExecutive)->create();

        $this->actingAs($user)
            ->postJson(route($routeName), ['depot_id' => $depot->id])
            ->assertForbidden();
    }

    #[DataProvider('endpointRoutes')]
    public function test_returns_validation_errors_for_incomplete_requests(string $routeName): void
    {
        $user = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();

        $this->actingAs($user)
            ->postJson(route($routeName))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('depot_id');
    }

    public function test_planner_calculates_capacity_without_a_depot_assignment(): void
    {
        $depot = Depot::factory()->create();
        $shift = Shift::factory()->for($depot)->create([
            'duration_minutes' => 600,
            'prestart_minutes' => 30,
            'cleaning_minutes' => 30,
            'performance_target_percent' => 80,
            'availability_target_percent' => 75,
        ]);
        $sku = SkuMaster::factory()->create(['standard_run_rate_per_hour' => 10]);
        $user = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();

        $this->actingAs($user)
            ->postJson(route('api.v1.scheduling.capacity-calculations.store'), [
                'depot_id' => $depot->id,
                'shift_id' => $shift->id,
                'sku_id' => $sku->id,
            ])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'effective_hours' => '5.400000',
                    'planned_pipes' => 54,
                ],
            ]);
    }

    public function test_capacity_rejects_a_shift_from_another_depot(): void
    {
        $assignedDepot = Depot::factory()->create();
        $otherDepot = Depot::factory()->create();
        $shift = Shift::factory()->for($otherDepot)->create();
        $sku = SkuMaster::factory()->create();
        $user = $this->depotUser(AppRole::DepotOperator, $assignedDepot);

        $this->actingAs($user)
            ->postJson(route('api.v1.scheduling.capacity-calculations.store'), [
                'depot_id' => $assignedDepot->id,
                'shift_id' => $shift->id,
                'sku_id' => $sku->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift_id');
    }

    public function test_depot_operator_cannot_calculate_capacity_for_an_unassigned_depot(): void
    {
        $assignedDepot = Depot::factory()->create();
        $otherDepot = Depot::factory()->create();
        $user = $this->depotUser(AppRole::DepotOperator, $assignedDepot);

        $this->actingAs($user)
            ->postJson(route('api.v1.scheduling.capacity-calculations.store'), [
                'depot_id' => $otherDepot->id,
            ])
            ->assertForbidden();
    }

    public function test_validates_a_conflict_free_mould_assignment(): void
    {
        $depot = Depot::factory()->create();
        $shift = Shift::factory()->for($depot)->create();
        $machine = MachineAsset::factory()->for($depot)->create();
        $sku = SkuMaster::factory()->create();
        $mould = MouldAsset::factory()->for($depot, 'currentDepot')->create();
        $user = $this->depotUser(AppRole::DepotOperator, $depot);

        $this->actingAs($user)
            ->postJson(route('api.v1.scheduling.mould-constraint-validations.store'), [
                'depot_id' => $depot->id,
                'shift_id' => $shift->id,
                'machine_asset_id' => $machine->id,
                'sku_id' => $sku->id,
                'mould_asset_id' => $mould->id,
                'planned_start_at' => '2026-09-20 08:00:00',
                'planned_end_at' => '2026-09-20 10:00:00',
                'planned_quantity' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.conflicts', [])
            ->assertJsonPath('data.changeover.required_blocks', []);
    }

    public function test_returns_a_safe_conflict_for_an_overlapping_mould(): void
    {
        $depot = Depot::factory()->create(['code' => 'API']);
        $shift = Shift::factory()->for($depot)->create();
        $machine = MachineAsset::factory()->for($depot)->create();
        $sku = SkuMaster::factory()->create();
        $mould = MouldAsset::factory()->for($depot, 'currentDepot')->create(['code' => 'API-MOULD']);
        $existing = ProductionSchedule::factory()
            ->for($depot)
            ->for($shift)
            ->for($machine, 'machine')
            ->for($sku, 'sku')
            ->for($mould, 'mould')
            ->create([
                'planned_start_at' => '2026-09-20 08:00:00',
                'planned_end_at' => '2026-09-20 10:00:00',
            ]);
        $user = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();

        $this->actingAs($user)
            ->postJson(route('api.v1.scheduling.mould-constraint-validations.store'), [
                'depot_id' => $depot->id,
                'shift_id' => $shift->id,
                'machine_asset_id' => $machine->id,
                'sku_id' => $sku->id,
                'mould_asset_id' => $mould->id,
                'planned_start_at' => '2026-09-20 09:00:00',
                'planned_end_at' => '2026-09-20 11:00:00',
                'planned_quantity' => 10,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'scheduling_constraint')
            ->assertJsonMissingPath('exception')
            ->assertJsonPath(
                'error.details.reasons.0',
                "Mould API-MOULD is already used by schedule {$existing->id} at API.",
            );
    }

    public function test_dispatcher_allocates_fifo_stock_for_an_assigned_depot(): void
    {
        $this->installLiveOrderBook();
        $depot = Depot::factory()->create([
            'erp_entity_code' => 'RCPVIC',
            'prime_stock_locations' => ['31'],
        ]);
        $sku = SkuMaster::factory()->create(['erp_entity_code' => 'RCPVIC']);
        $this->liveOrder($depot, $sku, 5, '2026-09-20');
        $this->liveStock($depot, $sku, 5);
        $user = $this->depotUser(AppRole::LogisticsDispatcher, $depot);

        $this->actingAs($user)
            ->postJson(route('api.v1.allocations.fifo.store'), [
                'depot_id' => $depot->id,
                'sku_id' => $sku->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.allocated_quantity', 5)
            ->assertJsonPath('data.unfulfilled_quantity', 0)
            ->assertJsonCount(1, 'data.allocation_ids');
        $this->assertSame(5, (int) OrderAllocation::query()->sum('quantity'));
    }

    public function test_dispatcher_cannot_allocate_fifo_for_an_unassigned_depot(): void
    {
        $assignedDepot = Depot::factory()->create();
        $otherDepot = Depot::factory()->create();
        $user = $this->depotUser(AppRole::LogisticsDispatcher, $assignedDepot);

        $this->actingAs($user)
            ->postJson(route('api.v1.allocations.fifo.store'), ['depot_id' => $otherDepot->id])
            ->assertForbidden();
    }

    public function test_fifo_allocation_returns_a_safe_domain_conflict(): void
    {
        $this->installLiveOrderBook();
        $depot = Depot::factory()->create([
            'erp_entity_code' => 'RCPVIC',
            'prime_stock_locations' => ['31'],
        ]);
        $sku = SkuMaster::factory()->create(['erp_entity_code' => 'RCPVIC']);
        $this->liveOrder($depot, $sku, 1, '2026-09-20');
        $this->liveStock($depot, $sku, 1);
        OrderAllocation::creating(
            fn (): never => throw new InvalidOrderAllocationException('Internal allocation detail.'),
        );
        $user = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();

        $this->actingAs($user)
            ->postJson(route('api.v1.allocations.fifo.store'), [
                'depot_id' => $depot->id,
                'sku_id' => $sku->id,
            ])
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'allocation_conflict',
                    'message' => 'The FIFO allocation could not be completed safely.',
                ],
            ]);
    }

    public function test_materials_buyer_calculates_steel_replenishment_for_an_assigned_depot(): void
    {
        $depot = Depot::factory()->create();
        $sku = SkuMaster::factory()->create([
            'wire_code' => 'WIRE-500L',
            'wire_kg_per_pipe' => '25.500',
        ]);
        // A pipe's steel lives in sku_wire_requirements now, one row per gauge it consumes.
        SkuWireRequirement::factory()->create([
            'sku_master_id' => $sku->id, 'wire_code' => 'WIRE-500L', 'kg_per_pipe' => '25.500',
        ]);
        $forecast = SteelForecast::factory()->for($depot)->create([
            'wire_code' => 'WIRE-500L',
            'on_hand_coils' => 12,
            'average_coil_weight_tonnes' => '0.0250',
            'on_order_coils' => 4,
            'transfer_in_coils' => 2,
            'transfer_out_coils' => 1,
            'safety_threshold_coils' => 0,
        ]);
        $user = $this->depotUser(AppRole::MaterialsBuyer, $depot);

        $this->actingAs($user)
            ->postJson(route('api.v1.steel.replenishment-calculations.store'), [
                'depot_id' => $depot->id,
                'steel_forecast_id' => $forecast->id,
                'demand_lines' => [['sku_id' => $sku->id, 'planned_quantity' => 10]],
                'rolling_weekly_consumption_kg' => [100, 200, 300],
            ])
            ->assertOk()
            ->assertJsonPath('data.wire_code', 'WIRE-500L')
            ->assertJsonPath('data.required_kg', '255.000')
            ->assertJsonPath('data.recommended_order_kg', '230.000');
    }

    public function test_materials_buyer_cannot_calculate_steel_for_an_unassigned_depot(): void
    {
        $assignedDepot = Depot::factory()->create();
        $otherDepot = Depot::factory()->create();
        $user = $this->depotUser(AppRole::MaterialsBuyer, $assignedDepot);

        $this->actingAs($user)
            ->postJson(route('api.v1.steel.replenishment-calculations.store'), [
                'depot_id' => $otherDepot->id,
            ])
            ->assertForbidden();
    }

    /**
     * A pipe that does not use the forecast's gauge contributes none of it, but a request where no
     * supplied product uses it at all is a mismatched pairing rather than an answer of zero.
     */
    public function test_steel_calculation_returns_a_safe_wire_group_conflict(): void
    {
        $depot = Depot::factory()->create();
        $sku = SkuMaster::factory()->create([
            'wire_code' => 'WIRE-500L',
            'wire_kg_per_pipe' => '20.000',
        ]);
        SkuWireRequirement::factory()->create([
            'sku_master_id' => $sku->id, 'wire_code' => 'WIRE-500L', 'kg_per_pipe' => '20.000',
        ]);
        $forecast = SteelForecast::factory()->for($depot)->create(['wire_code' => 'WIRE-500N']);
        $user = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();

        $this->actingAs($user)
            ->postJson(route('api.v1.steel.replenishment-calculations.store'), [
                'depot_id' => $depot->id,
                'steel_forecast_id' => $forecast->id,
                'demand_lines' => [['sku_id' => $sku->id, 'planned_quantity' => 5]],
            ])
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'domain_validation_failed',
                    'message' => 'The calculation inputs conflict with domain requirements.',
                ],
            ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endpointRoutes(): array
    {
        return [
            'capacity calculation' => ['api.v1.scheduling.capacity-calculations.store'],
            'mould validation' => ['api.v1.scheduling.mould-constraint-validations.store'],
            'FIFO allocation' => ['api.v1.allocations.fifo.store'],
            'steel calculation' => ['api.v1.steel.replenishment-calculations.store'],
        ];
    }

    private function depotUser(AppRole $role, Depot $depot): User
    {
        $user = User::factory()->withAppRoles($role)->create();
        $user->depots()->attach($depot);

        return $user;
    }
}
