<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Filament\Pages\PlanReview;
use App\Models\Depot;
use App\Models\MaterialRequirement;
use App\Models\PlanChangeLogEntry;
use App\Models\PlanRun;
use App\Models\ProductionSchedule;
use App\Models\SkuMaster;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlanReviewPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Depot $depot;

    private PlanRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->depot = Depot::factory()->create(['code' => 'MEL', 'name' => 'Melton']);
        $this->run = PlanRun::factory()->create([
            'status' => PlanRun::STATUS_DRAFT,
            'depot_ids' => [$this->depot->id],
            'metrics' => ['planned_eaches' => 1200, 'planned_tonnes' => 640.5, 'wire_tonnes' => 31.2, 'concrete_m3' => 370.0],
        ]);
    }

    public function test_a_planner_sees_the_plan_with_its_reasons_and_steel(): void
    {
        $line = $this->line('R0600-4', 340, 'covers_order');

        MaterialRequirement::factory()->create([
            'plan_run_id' => $this->run->id,
            'depot_id' => $this->depot->id,
            'material_code' => 'WIREPL6.23',
            'quantity' => 8280,
        ]);

        Livewire::actingAs($this->planner())
            ->test(PlanReview::class)
            ->assertSee('R0600-4')
            ->assertSee('Covers order')
            ->assertSee('WIREPL6.23')
            ->assertSee('Approve plan')
            ->assertSee('340');

        $this->assertSame('covers_order', $line->reason_code);
    }

    public function test_a_planner_can_change_a_line_and_the_change_is_recorded(): void
    {
        $line = $this->line('R0600-4', 340);

        Livewire::actingAs($this->planner())
            ->test(PlanReview::class)
            ->call('editLine', $line->id)
            ->set('editing.planned_quantity', 300)
            ->set('editReason', 'Mould is down after lunch')
            ->call('saveLine')
            ->assertHasNoErrors();

        $this->assertSame(300, $line->fresh()->planned_quantity);
        $this->assertSame('Mould is down after lunch', PlanChangeLogEntry::query()->sole()->reason);
    }

    public function test_a_planner_can_hold_a_line_against_regeneration(): void
    {
        $line = $this->line('R0600-4', 340);

        Livewire::actingAs($this->planner())
            ->test(PlanReview::class)
            ->call('toggleLock', $line->id)
            ->assertHasNoErrors();

        $this->assertTrue($line->fresh()->is_locked);
    }

    public function test_approving_the_plan_holds_every_line(): void
    {
        $line = $this->line('R0600-4', 340);

        Livewire::actingAs($this->planner())
            ->test(PlanReview::class)
            ->call('approve')
            ->assertHasNoErrors();

        $this->assertTrue($this->run->fresh()->isApproved());
        $this->assertTrue($line->fresh()->is_locked);
    }

    public function test_an_approved_plan_offers_no_edit_controls(): void
    {
        $this->line('R0600-4', 340);
        $this->run->update(['status' => PlanRun::STATUS_APPROVED, 'approved_at' => now()]);

        Livewire::actingAs($this->planner())
            ->test(PlanReview::class)
            ->assertSee('R0600-4')
            ->assertDontSee('Approve plan')
            ->assertSee('Generate a new plan to change it');
    }

    /**
     * An operator may read the plan for their own plant but not rewrite it.
     */
    public function test_an_operator_sees_the_plan_but_cannot_change_it(): void
    {
        $line = $this->line('R0600-4', 340);
        $operator = User::factory()->withAppRoles(AppRole::DepotOperator)->create();
        $operator->depots()->attach($this->depot);

        Livewire::actingAs($operator)
            ->test(PlanReview::class)
            ->assertSee('R0600-4')
            ->assertDontSee('Approve plan')
            ->call('editLine', $line->id)
            ->set('editing.planned_quantity', 1)
            ->call('saveLine')
            ->assertNotified('You can read this plan but not change it.');

        $this->assertSame(340, $line->fresh()->planned_quantity);
        $this->assertSame(0, PlanChangeLogEntry::query()->count());
    }

    /**
     * Depot scoping applies to writes as well as reads: an operator must not be able to reach a line
     * at a plant they are not assigned to by passing its id.
     */
    public function test_an_operator_cannot_reach_a_line_at_another_plant(): void
    {
        $otherDepot = Depot::factory()->create(['code' => 'KIL']);
        $otherLine = ProductionSchedule::factory()->create([
            'plan_run_id' => $this->run->id,
            'depot_id' => $otherDepot->id,
            'sku_master_id' => SkuMaster::factory()->create(['sku_code' => 'R0300-4'])->id,
            'planned_quantity' => 500,
        ]);

        $operator = User::factory()->withAppRoles(AppRole::DepotOperator)->create();
        $operator->depots()->attach($this->depot);

        Livewire::actingAs($operator)
            ->test(PlanReview::class)
            ->assertDontSee('R0300-4')
            ->call('toggleLock', $otherLine->id)
            ->assertNotified();

        $this->assertFalse($otherLine->fresh()->is_locked);
    }

    /**
     * Only PlannerAdmin can manage schedules today, and it sees every plant, so the depot scoping on
     * writes is defence in depth for when a scoped planner role exists. This covers the same guard by
     * the route that is reachable now: a line id that is not there.
     */
    public function test_a_line_that_is_not_there_is_reported_rather_than_erroring(): void
    {
        Livewire::actingAs($this->planner())
            ->test(PlanReview::class)
            ->call('toggleLock', 987654)
            ->assertNotified('That line is not in this plan, or not at a plant you can see.');
    }

    public function test_the_page_says_so_when_no_plan_has_been_generated(): void
    {
        PlanRun::query()->delete();

        Livewire::actingAs($this->planner())
            ->test(PlanReview::class)
            ->assertSee('No plan has been generated yet');
    }

    private function planner(): User
    {
        return User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();
    }

    private function line(string $skuCode, int $quantity, ?string $reasonCode = null): ProductionSchedule
    {
        return ProductionSchedule::factory()->create([
            'plan_run_id' => $this->run->id,
            'depot_id' => $this->depot->id,
            'sku_master_id' => SkuMaster::factory()->create(['sku_code' => $skuCode])->id,
            'planned_quantity' => $quantity,
            'reason_code' => $reasonCode,
            'origin' => ProductionSchedule::ORIGIN_SYSTEM,
        ]);
    }
}
