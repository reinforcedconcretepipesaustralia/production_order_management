<?php

namespace Tests\Feature\Services\Planning;

use App\Models\Depot;
use App\Models\PlanChangeLogEntry;
use App\Models\PlanRun;
use App\Models\ProductionSchedule;
use App\Models\SkuMaster;
use App\Models\User;
use App\Services\Planning\PlanReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The model's promise is that a human reviews and adjusts rather than authors. That only holds if an
 * adjustment survives the next regeneration and stays visible afterwards, so both are pinned here.
 */
class PlanReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlanReviewService $review;

    private PlanRun $run;

    private Depot $depot;

    private User $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->review = app(PlanReviewService::class);
        $this->depot = Depot::factory()->create(['code' => 'MEL']);
        $this->run = PlanRun::factory()->create([
            'status' => PlanRun::STATUS_DRAFT,
            'depot_ids' => [$this->depot->id],
        ]);
        $this->planner = User::factory()->create(['name' => 'Shane Bull']);
    }

    public function test_a_date_change_moves_the_shift_clock_with_the_day(): void
    {
        $line = $this->line([
            'schedule_date' => '2026-09-21',
            'planned_start_at' => '2026-09-21 06:00:00',
            'planned_end_at' => '2026-09-21 14:00:00',
        ]);

        $applied = $this->review->updateLine($line, ['schedule_date' => '2026-09-24'], $this->planner);

        $this->assertSame(1, $applied);
        $this->assertSame('2026-09-24', $line->fresh()->schedule_date->toDateString());
        $this->assertSame('2026-09-24 06:00:00', $line->fresh()->planned_start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-24 14:00:00', $line->fresh()->planned_end_at->format('Y-m-d H:i:s'));
        $this->assertSame('date', PlanChangeLogEntry::query()->sole()->field);
    }

    public function test_a_quantity_change_is_applied_and_recorded(): void
    {
        $line = $this->line(['planned_quantity' => 340]);

        $applied = $this->review->updateLine(
            $line,
            ['planned_quantity' => 300],
            $this->planner,
            'Mould is down after lunch',
        );

        $this->assertSame(1, $applied);
        $this->assertSame(300, $line->fresh()->planned_quantity);

        $entry = PlanChangeLogEntry::query()->sole();

        $this->assertSame('quantity', $entry->field);
        $this->assertSame('340', $entry->from_value);
        $this->assertSame('300', $entry->to_value);
        $this->assertSame('Mould is down after lunch', $entry->reason);
        $this->assertSame($this->planner->id, $entry->user_id);
        $this->assertSame('Quantity 340 → 300', $entry->summary());
    }

    /**
     * A line the planner has touched is theirs, so regeneration must not take it back. That is what
     * origin = manual means to PlanGenerator.
     */
    public function test_an_edited_line_becomes_the_planners(): void
    {
        $line = $this->line(['origin' => ProductionSchedule::ORIGIN_SYSTEM]);

        $this->review->updateLine($line, ['planned_quantity' => 50], $this->planner);

        $this->assertSame(ProductionSchedule::ORIGIN_MANUAL, $line->fresh()->origin);
        $this->assertFalse($line->fresh()->isReplaceableByPlanner());
    }

    public function test_a_change_that_changes_nothing_is_not_logged(): void
    {
        $line = $this->line(['planned_quantity' => 340]);

        $applied = $this->review->updateLine($line, ['planned_quantity' => 340], $this->planner);

        $this->assertSame(0, $applied);
        $this->assertSame(0, PlanChangeLogEntry::query()->count());
    }

    public function test_several_fields_are_logged_separately(): void
    {
        $line = $this->line(['planned_quantity' => 340, 'priority' => 0, 'is_steamed' => false]);

        $applied = $this->review->updateLine($line, [
            'planned_quantity' => 300,
            'priority' => 5,
            'is_steamed' => true,
        ], $this->planner);

        $this->assertSame(3, $applied);
        $this->assertSame(
            ['priority', 'quantity', 'steamed'],
            PlanChangeLogEntry::query()->orderBy('field')->pluck('field')->all(),
        );
    }

    public function test_a_field_the_engine_owns_cannot_be_changed_by_hand(): void
    {
        $line = $this->line();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[machine_asset_id] is not a field a planner may change.');

        $this->review->updateLine($line, ['machine_asset_id' => 99], $this->planner);
    }

    public function test_holding_a_line_is_recorded(): void
    {
        $line = $this->line(['is_locked' => false]);

        $this->review->setLocked($line, true, $this->planner, 'Customer confirmed this date');

        $this->assertTrue($line->fresh()->is_locked);

        $entry = PlanChangeLogEntry::query()->sole();

        $this->assertSame('locked', $entry->field);
        $this->assertSame('no', $entry->from_value);
        $this->assertSame('yes', $entry->to_value);
    }

    public function test_holding_a_line_that_is_already_held_changes_nothing(): void
    {
        $line = $this->line(['is_locked' => true]);

        $this->review->setLocked($line, true, $this->planner);

        $this->assertSame(0, PlanChangeLogEntry::query()->count());
    }

    /**
     * An approved plan is what the factory works to, so every line in it is held and the run stops
     * being regenerable.
     */
    public function test_approving_holds_every_line(): void
    {
        $this->line();
        $this->line();

        $this->review->approve($this->run, $this->planner);

        $this->assertTrue($this->run->fresh()->isApproved());
        $this->assertSame($this->planner->id, $this->run->fresh()->approved_by);
        $this->assertNotNull($this->run->fresh()->approved_at);
        $this->assertSame(2, ProductionSchedule::query()->where('is_locked', true)->count());
        $this->assertSame(
            ['scheduled', 'scheduled'],
            ProductionSchedule::query()->pluck('status')->all(),
        );
    }

    public function test_an_approved_plan_cannot_be_edited(): void
    {
        $line = $this->line();

        $this->review->approve($this->run, $this->planner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already approved');

        $this->review->updateLine($line->fresh(), ['planned_quantity' => 1], $this->planner);
    }

    public function test_an_approved_plan_cannot_be_approved_twice(): void
    {
        $this->review->approve($this->run, $this->planner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only a draft plan can be approved.');

        $this->review->approve($this->run->fresh(), $this->planner);
    }

    public function test_discarding_cancels_the_lines_so_nobody_works_to_them(): void
    {
        $this->line();

        $this->review->discard($this->run);

        $this->assertSame(PlanRun::STATUS_DISCARDED, $this->run->fresh()->status);
        $this->assertSame('cancelled', ProductionSchedule::query()->sole()->status);
    }

    public function test_an_approved_plan_cannot_be_discarded(): void
    {
        $this->review->approve($this->run, $this->planner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An approved plan cannot be discarded.');

        $this->review->discard($this->run->fresh());
    }

    /**
     * A log entry has to still read after the plan it described has been regenerated and its lines
     * deleted, so the line is described as well as referenced.
     */
    public function test_a_log_entry_survives_the_line_it_describes(): void
    {
        $line = $this->line();

        $this->review->updateLine($line, ['planned_quantity' => 7], $this->planner);

        $line->delete();

        $entry = PlanChangeLogEntry::query()->sole();

        $this->assertNull($entry->production_schedule_id);
        $this->assertStringContainsString('MEL', $entry->line_description);
        $this->assertStringContainsString('R0600-4', $entry->line_description);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function line(array $attributes = []): ProductionSchedule
    {
        return ProductionSchedule::factory()->create([
            'plan_run_id' => $this->run->id,
            'depot_id' => $this->depot->id,
            'sku_master_id' => SkuMaster::factory()->create(['sku_code' => 'R0600-4'])->id,
            ...$attributes,
        ]);
    }
}
