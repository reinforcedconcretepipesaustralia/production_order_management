<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Filament\Pages\ProductionBoard;
use App\Models\Depot;
use App\Models\PlanChangeLogEntry;
use App\Models\PlanRun;
use App\Models\ProductionSchedule;
use App\Models\Shift;
use App\Models\SkuMaster;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionBoardPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->travelTo('2026-09-21 09:00:00');
    }

    public function test_the_board_stacks_dates_with_shifts_and_lets_a_planner_drag_a_line(): void
    {
        [$depot, $run, $day, $afternoon] = $this->plant();
        $line = $this->line($depot, $run, $day, 'R0600-4', '2026-09-21');

        Livewire::actingAs($this->planner())
            ->test(ProductionBoard::class)
            ->assertSee('Production Plan')
            ->assertSee('Mon')
            ->assertSee('21 Sep 2026')
            ->assertSee('Day')
            ->assertSee('Afternoon')
            ->assertSee('R0600-4')
            ->assertSee('Approve this week')
            ->assertSee('Approve this month')
            ->call('moveLine', $line->id, '2026-09-22', $afternoon->id)
            ->assertHasNoErrors();

        $fresh = $line->fresh();

        $this->assertSame('2026-09-22', $fresh->schedule_date->toDateString());
        $this->assertSame($afternoon->id, $fresh->shift_id);
        $this->assertSame('2026-09-22 16:00:00', $fresh->planned_start_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            ['date', 'shift'],
            PlanChangeLogEntry::query()->orderBy('field')->pluck('field')->all(),
        );
    }

    public function test_approving_this_week_holds_only_that_week(): void
    {
        [$depot, $run, $day] = $this->plant();
        $thisWeek = $this->line($depot, $run, $day, 'WEEK-SKU', '2026-09-22');
        $nextMonth = $this->line($depot, $run, $day, 'MONTH-SKU', '2026-10-06');

        Livewire::actingAs($this->planner())
            ->test(ProductionBoard::class)
            ->set('fromDate', '2026-09-21')
            ->set('toDate', '2026-10-11')
            ->call('approveWeek')
            ->assertHasNoErrors();

        $this->assertTrue($thisWeek->fresh()->is_locked);
        $this->assertSame('scheduled', $thisWeek->fresh()->status);
        $this->assertFalse($nextMonth->fresh()->is_locked);
        $this->assertTrue($run->fresh()->isDraft());
    }

    public function test_an_operator_can_read_the_board_but_cannot_move_a_line(): void
    {
        [$depot, $run, $day, $afternoon] = $this->plant();
        $line = $this->line($depot, $run, $day, 'R0600-4', '2026-09-21');
        $operator = User::factory()->withAppRoles(AppRole::DepotOperator)->create();
        $operator->depots()->attach($depot);

        Livewire::actingAs($operator)
            ->test(ProductionBoard::class)
            ->assertSee('R0600-4')
            ->assertDontSee('Approve this week')
            ->call('moveLine', $line->id, '2026-09-22', $afternoon->id);

        $this->assertSame('2026-09-21', $line->fresh()->schedule_date->toDateString());
        $this->assertSame($day->id, $line->fresh()->shift_id);
        $this->assertSame(0, PlanChangeLogEntry::query()->count());
    }

    public function test_the_board_shows_product_mould_fields_instead_of_time(): void
    {
        [$depot, $run, $day] = $this->plant();
        $this->line($depot, $run, $day, 'R0600-4', '2026-09-21', [
            'mould_type' => 'FJ',
            'reo_cage' => 'single',
            'moulds_count' => 2,
            'pallets_count' => 25,
        ]);

        Livewire::actingAs($this->planner())
            ->test(ProductionBoard::class)
            ->assertSee('Mould type')
            ->assertSee('Reo cage')
            ->assertSee('Moulds #')
            ->assertSee('Pallets #')
            ->assertSee('FJ')
            ->assertSee('Single')
            ->assertDontSeeHtml('<th>Time</th>');
    }

    /**
     * @return array{0: Depot, 1: PlanRun, 2: Shift, 3: Shift}
     */
    private function plant(): array
    {
        $depot = Depot::factory()->create(['code' => 'MEL', 'name' => 'Melton']);
        $run = PlanRun::factory()->create([
            'status' => PlanRun::STATUS_DRAFT,
            'depot_ids' => [$depot->id],
            'horizon_from' => '2026-09-21',
            'horizon_to' => '2026-10-18',
        ]);
        $day = Shift::factory()->create([
            'depot_id' => $depot->id,
            'name' => 'Day',
            'starts_at' => '06:00:00',
            'is_active' => true,
        ]);
        $afternoon = Shift::factory()->create([
            'depot_id' => $depot->id,
            'name' => 'Afternoon',
            'starts_at' => '16:00:00',
            'is_active' => true,
        ]);

        return [$depot, $run, $day, $afternoon];
    }

    private function planner(): User
    {
        return User::factory()->withAppRoles(AppRole::PlannerAdmin)->create();
    }

    /**
     * @param  array<string, mixed>  $sku
     */
    private function line(Depot $depot, PlanRun $run, Shift $shift, string $skuCode, string $date, array $sku = []): ProductionSchedule
    {
        $day = CarbonImmutable::parse($date);

        return ProductionSchedule::factory()->create([
            'plan_run_id' => $run->id,
            'depot_id' => $depot->id,
            'shift_id' => $shift->id,
            'sku_master_id' => SkuMaster::factory()->create([
                'sku_code' => $skuCode,
                ...$sku,
            ])->id,
            'schedule_date' => $date,
            'planned_start_at' => $day->setTime(6, 0),
            'planned_end_at' => $day->setTime(14, 0),
            'planned_quantity' => 80,
            'status' => 'draft',
            'origin' => ProductionSchedule::ORIGIN_SYSTEM,
        ]);
    }
}
