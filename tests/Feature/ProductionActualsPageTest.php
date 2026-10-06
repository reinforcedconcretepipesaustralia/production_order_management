<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Enums\ProductionQualityStatus;
use App\Filament\Pages\ProductionActuals;
use App\Models\Depot;
use App\Models\ProductionActual;
use App\Models\ProductionSchedule;
use App\Models\SkuMaster;
use App\Models\User;
use App\Support\Authorization\ShieldRoleCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionActualsPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_the_page_requires_authentication(): void
    {
        $this->get(ProductionActuals::getUrl())->assertRedirect();
    }

    public function test_a_user_without_a_role_cannot_open_the_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(ProductionActuals::getUrl())->assertForbidden();
    }

    public function test_a_planner_cannot_open_the_page(): void
    {
        $planner = User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();

        $this->actingAs($planner);

        $permission = app(ShieldRoleCatalog::class)->pagePermissionKey(ProductionActuals::class);

        $this->assertNotNull($permission);
        $this->assertFalse($planner->can($permission));
        $this->get(ProductionActuals::getUrl())->assertForbidden();
    }

    public function test_an_executive_or_quality_inspector_cannot_open_the_page(): void
    {
        $executive = User::factory()->withAppRoles(AppRole::ViewerExecutive)->create();
        $inspector = User::factory()->withAppRoles(AppRole::QualityInspector)->create();
        $inspector->depots()->attach(Depot::factory()->create(['code' => 'QC']));

        $this->actingAs($executive)->get(ProductionActuals::getUrl())->assertForbidden();
        $this->actingAs($inspector)->get(ProductionActuals::getUrl())->assertForbidden();
    }

    public function test_an_operator_without_a_plant_assignment_cannot_open_the_page(): void
    {
        $operator = User::factory()->withAppRoles(AppRole::DepotOperator)->create();

        $this->actingAs($operator)->get(ProductionActuals::getUrl())->assertForbidden();
    }

    public function test_an_operator_sees_batches_at_their_plant_and_can_enter_the_good_count(): void
    {
        $depot = Depot::factory()->create(['code' => 'MEL', 'name' => 'Melton']);
        $operator = $this->operatorAt($depot);
        $line = $this->batch($depot, 'R0600-4', now()->toDateString(), 80);

        Livewire::actingAs($operator)
            ->test(ProductionActuals::class)
            ->assertSee('R0600-4')
            ->assertSee('80')
            ->call('updateTableColumnState', 'good_quantity', (string) $line->getKey(), 76)
            ->assertHasNoErrors();

        $actual = $line->fresh()->latestActual;

        $this->assertNotNull($actual);
        $this->assertSame(76, $actual->good_quantity);
        $this->assertSame(ProductionQualityStatus::Curing, $actual->quality_status);
        $this->assertNull($actual->qc_released_at);
    }

    public function test_an_operator_does_not_see_or_change_a_batch_at_another_plant(): void
    {
        $assigned = Depot::factory()->create(['code' => 'MEL']);
        $other = Depot::factory()->create(['code' => 'KIL']);
        $operator = $this->operatorAt($assigned);
        $this->batch($assigned, 'VISIBLE-SKU', now()->toDateString(), 40);
        $hidden = $this->batch($other, 'HIDDEN-SKU', now()->toDateString(), 50);

        Livewire::actingAs($operator)
            ->test(ProductionActuals::class)
            ->assertSee('VISIBLE-SKU')
            ->assertDontSee('HIDDEN-SKU')
            ->call('updateTableColumnState', 'good_quantity', (string) $hidden->getKey(), 1)
            ->assertNotified();

        $this->assertNull($hidden->fresh()->latestActual);
    }

    public function test_dumped_quantity_without_a_reason_does_not_persist(): void
    {
        $depot = Depot::factory()->create(['code' => 'MEL']);
        $operator = $this->operatorAt($depot);
        $line = $this->batch($depot, 'R0600-4', now()->toDateString(), 80);

        Livewire::actingAs($operator)
            ->test(ProductionActuals::class)
            ->call('updateTableColumnState', 'dumped_quantity', (string) $line->getKey(), 2)
            ->assertNotified();

        $this->assertSame(0, $line->actuals()->count());
    }

    public function test_a_cancelled_batch_is_not_shown(): void
    {
        $depot = Depot::factory()->create(['code' => 'MEL']);
        $operator = $this->operatorAt($depot);
        $this->batch($depot, 'THROWN-AWAY', now()->toDateString(), 10, 'cancelled');
        $this->batch($depot, 'R0600-4', now()->toDateString(), 80);

        Livewire::actingAs($operator)
            ->test(ProductionActuals::class)
            ->assertSee('R0600-4')
            ->assertDontSee('THROWN-AWAY');
    }

    public function test_a_batch_outside_the_date_window_is_not_shown(): void
    {
        $depot = Depot::factory()->create(['code' => 'MEL']);
        $operator = $this->operatorAt($depot);
        $this->batch($depot, 'OLD-SKU', now()->subDays(21)->toDateString(), 10);
        $this->batch($depot, 'TODAY-SKU', now()->toDateString(), 80);

        Livewire::actingAs($operator)
            ->test(ProductionActuals::class)
            ->assertSee('TODAY-SKU')
            ->assertDontSee('OLD-SKU');
    }

    public function test_a_released_batch_cannot_be_changed_from_the_page(): void
    {
        $depot = Depot::factory()->create(['code' => 'MEL']);
        $operator = $this->operatorAt($depot);
        $line = $this->batch($depot, 'R0600-4', now()->toDateString(), 80);
        ProductionActual::factory()->for($line, 'schedule')->create([
            'manufactured_at' => now()->subDays(8),
            'good_quantity' => 40,
            'quality_status' => ProductionQualityStatus::Released,
            'qc_released_at' => now()->subDay(),
            'qc_released_by' => 'quality.manager@rcpa.com.au',
        ]);

        Livewire::actingAs($operator)
            ->test(ProductionActuals::class)
            ->call('updateTableColumnState', 'good_quantity', (string) $line->getKey(), 41)
            ->assertNotified();

        $this->assertSame(40, $line->fresh()->latestActual?->good_quantity);
    }

    private function operatorAt(Depot $depot): User
    {
        $operator = User::factory()->withAppRoles(AppRole::DepotOperator)->create();
        $operator->depots()->attach($depot);

        return $operator;
    }

    private function batch(Depot $depot, string $skuCode, string $date, int $quantity, string $status = 'scheduled'): ProductionSchedule
    {
        return ProductionSchedule::factory()->create([
            'depot_id' => $depot->id,
            'sku_master_id' => SkuMaster::factory()->create(['sku_code' => $skuCode])->id,
            'schedule_date' => $date,
            'planned_start_at' => $date.' 06:00:00',
            'planned_end_at' => $date.' 14:00:00',
            'planned_quantity' => $quantity,
            'status' => $status,
        ]);
    }
}
