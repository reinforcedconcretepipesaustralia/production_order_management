<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Filament\Pages\DemandVsProdPlan;
use App\Models\Depot;
use App\Models\InventorySoh;
use App\Models\ProductionSchedule;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DemandVsProdPlanPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_an_operator_sees_assigned_plant_rows_and_red_negative_variance(): void
    {
        $this->travelTo('2026-10-01 09:00:00');

        $assignedDepot = Depot::factory()->create(['code' => 'ASSIGNED']);
        $otherDepot = Depot::factory()->create(['code' => 'OTHER']);
        $operator = User::factory()->withAppRoles(AppRole::DepotOperator)->create();
        $operator->depots()->attach($assignedDepot);

        $visible = SkuMaster::factory()->create(['sku_code' => 'R0675-4']);
        $hidden = SkuMaster::factory()->create(['sku_code' => 'HIDDEN-SKU']);

        InventorySoh::factory()->for($assignedDepot)->for($visible, 'sku')->create([
            'prime_quantity' => 10,
        ]);
        SalesOrder::factory()->for($assignedDepot)->for($visible, 'sku')->create([
            'ordered_quantity' => 35,
            'allocated_quantity' => 0,
            'requested_delivery_date' => '2026-10-12',
            'status' => 'open',
        ]);
        $this->schedule($assignedDepot, $visible, '2026-10-08', 20);

        InventorySoh::factory()->for($otherDepot)->for($hidden, 'sku')->create([
            'prime_quantity' => 99,
        ]);
        SalesOrder::factory()->for($otherDepot)->for($hidden, 'sku')->create([
            'ordered_quantity' => 50,
            'requested_delivery_date' => '2026-10-12',
        ]);

        Livewire::actingAs($operator)
            ->test(DemandVsProdPlan::class)
            ->assertSee('Demand vs Prod Plan')
            ->assertSee('R0675-4')
            ->assertSee('(15)')
            ->assertSeeHtml('is-negative')
            ->assertDontSee('HIDDEN-SKU');
    }

    public function test_product_search_keeps_matching_rows_only(): void
    {
        $this->travelTo('2026-10-01 09:00:00');

        $depot = Depot::factory()->create(['code' => 'MEL']);
        $planner = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();
        $kept = SkuMaster::factory()->create(['sku_code' => 'R0600-4']);
        $dropped = SkuMaster::factory()->create(['sku_code' => 'R1200-2']);

        InventorySoh::factory()->for($depot)->for($kept, 'sku')->create(['prime_quantity' => 4]);
        InventorySoh::factory()->for($depot)->for($dropped, 'sku')->create(['prime_quantity' => 8]);

        Livewire::actingAs($planner)
            ->test(DemandVsProdPlan::class)
            ->assertSee('R0600-4')
            ->assertSee('R1200-2')
            ->set('productSearch', 'R0600')
            ->assertSee('R0600-4')
            ->assertDontSee('R1200-2');
    }

    public function test_escapes_product_codes_in_the_matrix(): void
    {
        $this->travelTo('2026-10-01 09:00:00');

        $depot = Depot::factory()->create();
        $planner = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();
        $sku = SkuMaster::factory()->create([
            'sku_code' => 'R0600<script>alert(1)</script>',
        ]);

        InventorySoh::factory()->for($depot)->for($sku, 'sku')->create([
            'prime_quantity' => 3,
        ]);

        Livewire::actingAs($planner)
            ->test(DemandVsProdPlan::class)
            ->assertSee('R0600&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
            ->assertDontSee('<script>alert(1)</script>', escape: false);
    }

    private function schedule(Depot $depot, SkuMaster $sku, string $date, int $quantity): ProductionSchedule
    {
        $day = CarbonImmutable::parse($date);

        return ProductionSchedule::factory()->create([
            'depot_id' => $depot->id,
            'sku_master_id' => $sku->id,
            'schedule_date' => $date,
            'planned_start_at' => $day->addHours(6),
            'planned_end_at' => $day->addHours(14),
            'planned_quantity' => $quantity,
            'status' => 'scheduled',
        ]);
    }
}
