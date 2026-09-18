<?php

namespace Tests\Feature;

use App\Enums\ProductionQualityStatus;
use App\Models\Depot;
use App\Models\DispatchLoad;
use App\Models\DispatchLoadItem;
use App\Models\InventorySoh;
use App\Models\MachineAsset;
use App\Models\MouldAsset;
use App\Models\OrderAllocation;
use App\Models\ProductionActual;
use App\Models\ProductionSchedule;
use App\Models\SalesOrder;
use App\Models\Shift;
use App\Models\SkuMaster;
use App\Models\SteelForecast;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OperationalDataFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_schedule_and_actuals_preserve_oee_inputs_and_relationships(): void
    {
        $depot = Depot::factory()->create();
        $shift = Shift::factory()->for($depot)->create([
            'duration_minutes' => 600,
            'prestart_minutes' => 15,
            'cleaning_minutes' => 30,
            'performance_target_percent' => 90,
            'availability_target_percent' => 95,
        ]);
        $machine = MachineAsset::factory()->for($depot)->create();
        $mould = MouldAsset::factory()->for($depot, 'currentDepot')->create();
        $sku = SkuMaster::factory()->create();
        $schedule = ProductionSchedule::factory()
            ->for($depot)
            ->for($shift)
            ->for($machine, 'machine')
            ->for($mould, 'mould')
            ->for($sku, 'sku')
            ->create([
                'planned_oee_percent' => 85.25,
                'effective_hours' => 7.125,
            ]);
        $actual = ProductionActual::factory()->for($schedule, 'schedule')->create([
            'good_quantity' => 40,
            'dumped_quantity' => 2,
            'dump_reason' => 'Damaged socket',
            'quality_percent' => 95.24,
        ]);

        $this->assertTrue($schedule->depot->is($depot));
        $this->assertTrue($schedule->shift->is($shift));
        $this->assertTrue($actual->schedule->is($schedule));
        $this->assertSame('85.25', $schedule->planned_oee_percent);
        $this->assertSame('7.125', $schedule->effective_hours);
        $this->assertSame('95.24', $actual->quality_percent);
    }

    public function test_operational_defaults_are_applied_when_values_are_omitted(): void
    {
        $depot = Depot::factory()->create();
        $shift = Shift::factory()->for($depot)->create();
        $machine = MachineAsset::factory()->for($depot)->create();
        $sku = SkuMaster::factory()->create();
        $schedule = ProductionSchedule::query()->create([
            'depot_id' => $depot->id,
            'shift_id' => $shift->id,
            'machine_asset_id' => $machine->id,
            'sku_master_id' => $sku->id,
            'schedule_date' => '2026-09-20',
            'planned_start_at' => '2026-09-20 06:00:00',
            'planned_end_at' => '2026-09-20 14:00:00',
            'planned_quantity' => 50,
            'effective_hours' => 7.25,
        ]);
        $actual = ProductionActual::query()->create([
            'production_schedule_id' => $schedule->id,
            'manufactured_at' => '2026-09-20 12:00:00',
        ]);

        $this->assertSame('100.00', $schedule->planned_oee_percent);
        $this->assertSame('draft', $schedule->status);
        $this->assertSame(0, $schedule->changeover_minutes);
        $this->assertSame(0, $actual->good_quantity);
        $this->assertSame(0, $actual->dumped_quantity);
        $this->assertSame('100.00', $actual->quality_percent);
        $this->assertSame(ProductionQualityStatus::Curing, $actual->quality_status);
    }

    public function test_inventory_is_unique_per_depot_sku_and_bin(): void
    {
        $inventory = InventorySoh::factory()->create();

        $this->expectException(QueryException::class);

        InventorySoh::factory()->create([
            'depot_id' => $inventory->depot_id,
            'sku_master_id' => $inventory->sku_master_id,
            'bin_location' => $inventory->bin_location,
        ]);
    }

    public function test_wire_identity_and_coil_forecast_are_persisted_by_depot_and_wire_code(): void
    {
        $depot = Depot::factory()->create();
        $sku = SkuMaster::factory()->create([
            'wire_code' => 'WIRE-500L-8MM',
            'wire_kg_per_pipe' => '42.375',
        ]);
        $forecast = SteelForecast::factory()->for($depot)->create([
            'wire_code' => $sku->wire_code,
            'on_hand_coils' => 5,
            'average_coil_weight_tonnes' => '2.2500',
            'on_order_coils' => 2,
            'transfer_in_coils' => 1,
            'transfer_out_coils' => 3,
            'safety_threshold_coils' => 4,
        ]);

        $this->assertSame('WIRE-500L-8MM', $sku->fresh()->wire_code);
        $this->assertSame('42.375', $sku->fresh()->wire_kg_per_pipe);
        $this->assertSame('WIRE-500L-8MM', $forecast->fresh()->wire_code);
        $this->assertSame('2.2500', $forecast->fresh()->average_coil_weight_tonnes);
        $this->assertSame(5, $forecast->fresh()->on_hand_coils);
        $this->assertSame(2, $forecast->fresh()->on_order_coils);
        $this->assertSame(1, $forecast->fresh()->transfer_in_coils);
        $this->assertSame(3, $forecast->fresh()->transfer_out_coils);
        $this->assertSame(4, $forecast->fresh()->safety_threshold_coils);
    }

    public function test_allocations_are_durable_and_dispatch_loads_group_orders(): void
    {
        $depot = Depot::factory()->create();
        $firstOrder = SalesOrder::factory()->for($depot)->create(['ordered_at' => '2026-09-01 08:00:00']);
        $secondOrder = SalesOrder::factory()->for($depot)->create(['ordered_at' => '2026-09-02 08:00:00']);
        $allocation = OrderAllocation::factory()->for($firstOrder)->create([
            'quantity' => 12,
            'allocated_at' => '2026-09-03 09:00:00',
        ]);
        $secondAllocation = OrderAllocation::factory()->for($secondOrder)->create(['quantity' => 6]);
        $load = DispatchLoad::factory()->for($depot)->create();
        DispatchLoadItem::factory()->for($load)->for($allocation, 'orderAllocation')->create(['quantity' => 8]);
        DispatchLoadItem::factory()->for($load)->for($secondAllocation, 'orderAllocation')->create(['quantity' => 6]);

        $this->assertSame(12, $allocation->fresh()->quantity);
        $this->assertSame(
            [$firstOrder->id, $secondOrder->id],
            $load->items()->with('orderAllocation')->orderBy('id')->get()->pluck('orderAllocation.sales_order_id')->all(),
        );
    }

    public function test_reference_seeding_creates_only_expected_depots_and_shifts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(['KIL', 'MEL'], Depot::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame(12, Shift::query()->count());
        $this->assertSame(
            ['AFT-FRI', 'AFT-MON', 'AFT-THU', 'AFT-TUE', 'AFT-WED', 'DAY'],
            Depot::query()->where('code', 'MEL')->firstOrFail()->shifts()->orderBy('code')->pluck('code')->all(),
        );
        $this->assertSame(
            [
                'AFT-FRI' => 600,
                'AFT-MON' => 600,
                'AFT-THU' => 720,
                'AFT-TUE' => 720,
                'AFT-WED' => 720,
                'DAY' => 600,
            ],
            Depot::query()->where('code', 'KIL')->firstOrFail()->shifts()->orderBy('code')->pluck('duration_minutes', 'code')->all(),
        );
        $this->assertSame(0, SalesOrder::query()->count());
        $this->assertSame(0, ProductionSchedule::query()->count());
    }
}
