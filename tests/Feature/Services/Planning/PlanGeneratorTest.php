<?php

namespace Tests\Feature\Services\Planning;

use App\Models\Depot;
use App\Models\DepotShiftPattern;
use App\Models\DowntimeEvent;
use App\Models\MachineAsset;
use App\Models\MachineSkuRunRate;
use App\Models\NonProductionDay;
use App\Models\OeeSetting;
use App\Models\PlanRun;
use App\Models\ProductionSchedule;
use App\Models\Shift;
use App\Models\SkuMaster;
use App\Services\Planning\PlanGenerator;
use App\Services\Planning\PlanRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SeedsLiveOrderBook;
use Tests\TestCase;

/**
 * The planner has to be trustworthy before anyone will run a factory off it, so its behaviour on
 * the decisions that matter is pinned down here: what it makes first, what it refuses to touch,
 * and what it leaves alone because a human said so.
 */
class PlanGeneratorTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLiveOrderBook;

    /** Monday 7 September 2026. */
    private CarbonImmutable $monday;

    private Depot $depot;

    private MachineAsset $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monday = CarbonImmutable::parse('2026-09-07');
        $this->installLiveOrderBook();
        $this->depot = $this->plant();
        $this->machine = $this->depot->machines->first();
    }

    public function test_plans_order_backed_work_before_anything_without_a_backorder(): void
    {
        // The fastest seller, fully covered by stock, so it has no backorder.
        $covered = $this->sku('R0300-4', ratePerHour: 30);
        $this->salesOf($covered, 900);
        $this->stockOf($covered, 500);

        // A slower product that owes a customer.
        $owed = $this->sku('R1800-2', ratePerHour: 6);
        $this->salesOf($owed, 20);
        $this->order($owed, quantity: 40, due: '2026-09-30');

        $run = $this->generate();
        $first = $run->schedules->sortBy('planned_start_at')->first();

        $this->assertSame('R1800-2', $first->sku->sku_code);
        $this->assertSame('order_due', $first->reason_code);
    }

    public function test_never_schedules_a_sku_the_machine_is_not_qualified_for(): void
    {
        $qualified = $this->sku('R0300-4', ratePerHour: 30);
        $this->order($qualified, quantity: 50, due: '2026-09-30');

        // No machine run rate on file, so the planner has no proven rate to schedule against.
        $unqualified = SkuMaster::factory()->create([
            'sku_code' => 'R2100-2',
            'standard_run_rate_per_hour' => 5,
            'unit_weight_tonnes' => 3,
        ]);
        $this->order($unqualified, quantity: 500, due: '2026-09-08');

        $run = $this->generate();

        $this->assertNotContains(
            'R2100-2',
            $run->schedules->map(fn (ProductionSchedule $line): string => $line->sku->sku_code)->all(),
        );
        $this->assertContains('R0300-4', $run->schedules->map(fn (ProductionSchedule $line): string => $line->sku->sku_code)->all());
        // And it is reported as unmet rather than quietly dropped.
        $this->assertContains('R2100-2', $run->metrics['by_depot']['MEL']['skus_short']);
    }

    public function test_a_run_continues_across_shifts_until_the_order_is_filled(): void
    {
        $sku = $this->sku('R0300-4', ratePerHour: 30);
        // 30/hr over 9 effective hours at full OEE is 270 a shift, so 600 needs three shifts.
        $this->order($sku, quantity: 600, due: '2026-09-30');

        $run = $this->generate();
        $lines = $run->schedules->sortBy('planned_start_at')->values();

        $this->assertGreaterThanOrEqual(3, $lines->count());
        $this->assertSame(600, $lines->sum('planned_quantity'));
        $this->assertTrue($lines->every(fn (ProductionSchedule $line): bool => $line->sku->sku_code === 'R0300-4'));
    }

    public function test_the_last_shift_of_a_run_is_only_as_long_as_it_needs_to_be(): void
    {
        $sku = $this->sku('R0300-4', ratePerHour: 30);
        // 270 a shift, so 300 fills one shift and needs a small part of a second.
        $this->order($sku, quantity: 300, due: '2026-09-30');

        $lines = $this->generate()->schedules->sortBy('planned_start_at')->values();

        $this->assertSame('1.0000', $lines[0]->shift_fraction);
        $this->assertSame(270, $lines[0]->planned_quantity);
        $this->assertLessThan(1.0, (float) $lines[1]->shift_fraction);
        $this->assertSame(30, $lines[1]->planned_quantity);
    }

    public function test_every_line_records_why_it_is_there(): void
    {
        $sku = $this->sku('R0300-4', ratePerHour: 30);
        $this->order($sku, quantity: 50, due: '2026-06-01');

        $line = $this->generate()->schedules->first();

        $this->assertSame('order_overdue', $line->reason_code);
        $this->assertSame('order_backed', $line->reason_context['source']);
        $this->assertStringContainsString('overdue', $line->reason_context['explanation']);
        $this->assertSame(50, $line->reason_context['planned_quantity']);
    }

    public function test_safety_stock_is_built_only_once_the_order_book_is_covered(): void
    {
        $sku = $this->sku('R0300-4', ratePerHour: 30);
        $this->salesOf($sku, 270);
        $this->order($sku, quantity: 100, due: '2026-09-30');

        $run = $this->generate();
        $sources = $run->schedules
            ->sortBy('planned_start_at')
            ->map(fn (ProductionSchedule $line): string => $line->reason_context['source'])
            ->values()
            ->all();

        $this->assertSame('order_backed', $sources[0]);
        $this->assertContains('replenishment', $sources);
        // Replenishment never appears before the order-backed work is done.
        $this->assertSame('order_backed', $sources[array_search('replenishment', $sources, true) - 1]);
    }

    public function test_orders_only_plans_no_safety_stock_at_all(): void
    {
        $sku = $this->sku('R0300-4', ratePerHour: 30);
        $this->salesOf($sku, 270);
        $this->order($sku, quantity: 100, due: '2026-09-30');

        $run = app(PlanGenerator::class)->generate(new PlanRequest(
            from: $this->monday,
            to: $this->monday->addDays(4),
            includeReplenishment: false,
        ));

        $this->assertSame(100, $run->schedules->sum('planned_quantity'));
        $this->assertTrue($run->schedules->every(
            fn (ProductionSchedule $line): bool => $line->reason_context['source'] === 'order_backed',
        ));
    }

    public function test_a_locked_line_survives_regeneration_and_counts_against_demand(): void
    {
        $sku = $this->sku('R0300-4', ratePerHour: 30);
        $this->order($sku, quantity: 600, due: '2026-09-30');

        $locked = $this->existingLine($sku, quantity: 100, locked: true, origin: ProductionSchedule::ORIGIN_SYSTEM);

        $run = $this->generate();

        $this->assertNotNull($locked->fresh(), 'A locked line must never be replaced.');
        // The plan tops up the remaining 500 rather than scheduling the full 600 again.
        $this->assertSame(500, $run->schedules->sum('planned_quantity'));
    }

    public function test_a_hand_entered_line_is_never_replaced(): void
    {
        $sku = $this->sku('R0300-4', ratePerHour: 30);
        $this->order($sku, quantity: 600, due: '2026-09-30');

        $manual = $this->existingLine($sku, quantity: 80, locked: false, origin: ProductionSchedule::ORIGIN_MANUAL);

        $run = $this->generate();

        $this->assertNotNull($manual->fresh());
        $this->assertSame(520, $run->schedules->sum('planned_quantity'));
    }

    public function test_regenerating_replaces_the_previous_system_lines(): void
    {
        $sku = $this->sku('R0300-4', ratePerHour: 30);
        $this->order($sku, quantity: 300, due: '2026-09-30');

        $first = $this->generate();
        $second = $this->generate();

        $this->assertSame(0, ProductionSchedule::query()->where('plan_run_id', $first->id)->count());
        $this->assertSame(300, $second->schedules->sum('planned_quantity'));
        // The same inputs produce the same plan.
        $this->assertSame(
            $first->metrics['planned_eaches'],
            $second->metrics['planned_eaches'],
        );
    }

    /**
     * schedule_date is stored with a midnight time, so a bare date as the upper bound of the
     * horizon silently misses the last day. That left the previous run's final day in place and
     * booked every machine twice for it.
     */
    public function test_regenerating_does_not_double_book_the_last_day_of_the_horizon(): void
    {
        $sku = $this->sku('R0300-4', ratePerHour: 30);
        $this->order($sku, quantity: 10_000, due: '2026-09-30');

        $this->generate();
        $this->generate();

        $lastDay = ProductionSchedule::query()
            ->whereDate('schedule_date', $this->monday->addDays(4)->toDateString())
            ->get();

        $this->assertGreaterThan(0, $lastDay->count(), 'The last day should be planned at all.');
        $this->assertSame(
            $lastDay->count(),
            $lastDay->unique(fn (ProductionSchedule $line): string => $line->machine_asset_id
                .'|'.$line->shift_id
                .'|'.$line->planned_start_at->toDateTimeString())->count(),
            'The last day of the horizon must not be scheduled twice.',
        );
    }

    public function test_shutdowns_and_downtime_remove_capacity_from_the_plan(): void
    {
        $sku = $this->sku('R0300-4', ratePerHour: 30);
        $this->order($sku, quantity: 10_000, due: '2026-09-30');

        NonProductionDay::factory()->create([
            'depot_id' => $this->depot->id,
            'date' => '2026-09-09',
            'reason' => 'Plant shutdown',
        ]);

        $run = $this->generate();
        $dates = $run->schedules->map(fn (ProductionSchedule $line): string => $line->schedule_date->toDateString())->unique();

        $this->assertNotContains('2026-09-09', $dates->all());
        $this->assertCount(4, $dates);
    }

    public function test_a_change_over_is_recorded_when_the_product_changes(): void
    {
        $first = $this->sku('R0300-4', ratePerHour: 30, changeoverMinutes: 60);
        $second = $this->sku('R0450-4', ratePerHour: 30, changeoverMinutes: 60);

        // A short order, then a later-due one, so the machine has to change over.
        $this->order($first, quantity: 50, due: '2026-09-08');
        $this->order($second, quantity: 50, due: '2026-09-09');

        $run = $this->generate();
        $changed = $run->schedules->firstWhere('changeover_minutes', '>', 0);

        $this->assertNotNull($changed, 'Switching product should record a change-over.');
        $this->assertSame('R0300-4', $changed->changeover_from_sku_code);
        $this->assertSame(1, DowntimeEvent::query()
            ->where('category', DowntimeEvent::CATEGORY_CHANGEOVER)
            ->where('origin', ProductionSchedule::ORIGIN_SYSTEM)
            ->count());
    }

    /**
     * A machine can only be set up for so many products in a shift. Without a cap the planner
     * fills the tail of a shift with a run of four pipes and then another of six, paying a
     * change-over for each.
     */
    public function test_a_shift_is_never_split_across_more_products_than_allowed(): void
    {
        // Six products, each with a tiny order, all runnable on the one machine.
        foreach (range(1, 6) as $index) {
            $sku = $this->sku("R0{$index}00-4", ratePerHour: 30);
            $this->order($sku, quantity: 6, due: '2026-09-3'.min(9, $index));
        }

        $run = $this->generate();

        $perShift = $run->schedules
            ->groupBy(fn (ProductionSchedule $line): string => $line->machine_asset_id
                .'|'.$line->shift_id
                .'|'.$line->schedule_date->toDateString())
            ->map(fn ($lines): int => $lines->count());

        $this->assertTrue(
            $perShift->every(fn (int $count): bool => $count <= 2),
            'No shift should carry more than the two runs allowed by default.',
        );
    }

    public function test_the_run_cap_is_adjustable(): void
    {
        foreach (range(1, 4) as $index) {
            $sku = $this->sku("R0{$index}00-4", ratePerHour: 30);
            $this->order($sku, quantity: 6, due: '2026-09-30');
        }

        $run = app(PlanGenerator::class)->generate(new PlanRequest(
            from: $this->monday,
            to: $this->monday,
            maxRunsPerShift: 4,
        ));

        $this->assertSame(4, $run->schedules->count());
        $this->assertSame(4, $run->parameters['max_runs_per_shift']);
    }

    public function test_consecutive_runs_meet_exactly_and_never_overlap(): void
    {
        $first = $this->sku('R0300-4', ratePerHour: 30);
        $second = $this->sku('R0450-4', ratePerHour: 30);
        // Odd quantities so the shift splits on a fraction that does not land on a whole minute.
        $this->order($first, quantity: 37, due: '2026-09-08');
        $this->order($second, quantity: 61, due: '2026-09-09');

        $lines = $this->generate()->schedules->sortBy('planned_start_at')->values();

        $this->assertGreaterThan(1, $lines->count());

        for ($index = 1; $index < $lines->count(); $index++) {
            $previous = $lines[$index - 1];
            $current = $lines[$index];

            if ($previous->machine_asset_id !== $current->machine_asset_id) {
                continue;
            }

            $this->assertTrue(
                $current->planned_start_at->greaterThanOrEqualTo($previous->planned_end_at),
                "Run {$current->id} starts before run {$previous->id} finishes on the same machine.",
            );
        }
    }

    public function test_metrics_summarise_what_the_plan_does_and_what_it_misses(): void
    {
        $sku = $this->sku('R0300-4', ratePerHour: 30);
        // Far more than a week of capacity, so the plan is deliberately short.
        $this->order($sku, quantity: 5_000, due: '2026-09-30');

        $metrics = $this->generate()->metrics;

        $this->assertSame(1_350, $metrics['planned_eaches'], '5 shifts x 270 a shift.');
        $this->assertSame(337.5, $metrics['planned_tonnes'], '1350 pipes at 0.25 t.');
        $this->assertSame(3_650, $metrics['by_depot']['MEL']['unmet_order_eaches']);
        $this->assertSame(['R0300-4'], $metrics['by_depot']['MEL']['skus_short']);
        $this->assertSame(5, $metrics['by_depot']['MEL']['shift_slots']);
        $this->assertSame(5, $metrics['by_depot']['MEL']['shift_slots_used']);
        $this->assertArrayHasKey('2026-09', $metrics['by_month']);
    }

    public function test_a_run_is_recorded_as_a_reviewable_draft(): void
    {
        $sku = $this->sku('R0300-4', ratePerHour: 30);
        $this->order($sku, quantity: 50, due: '2026-09-30');

        $run = $this->generate();

        $this->assertSame(PlanRun::STATUS_DRAFT, $run->status);
        $this->assertTrue($run->isDraft());
        $this->assertSame([$this->depot->id], $run->depot_ids);
        $this->assertSame('2026-09-07', $run->horizon_from->toDateString());
        $this->assertSame(0.25, $run->parameters['minimum_run_fraction']);
    }

    public function test_a_plant_excluded_from_planning_is_not_scheduled(): void
    {
        $sku = $this->sku('R0300-4', ratePerHour: 30);
        $this->order($sku, quantity: 50, due: '2026-09-30');

        $this->depot->update(['is_planning_enabled' => false]);

        $this->expectException(\RuntimeException::class);

        $this->generate();
    }

    public function test_nothing_is_planned_when_there_is_no_demand(): void
    {
        $this->sku('R0300-4', ratePerHour: 30);

        $run = $this->generate();

        $this->assertSame(0, $run->schedules->count());
        $this->assertSame(0, $run->metrics['planned_eaches']);
    }

    private function generate(): PlanRun
    {
        return app(PlanGenerator::class)->generate(new PlanRequest(
            from: $this->monday,
            to: $this->monday->addDays(4),
        ));
    }

    /**
     * Melton dayshift, one machine, 9 effective hours at full performance and availability so the
     * arithmetic in these tests stays legible.
     */
    private function plant(): Depot
    {
        $depot = Depot::factory()->create([
            'code' => 'MEL',
            'name' => 'Melton',
            'erp_entity_code' => 'RCPVIC',
            'prime_stock_locations' => ['31'],
            'is_planning_enabled' => true,
        ]);

        $shift = Shift::factory()->create([
            'depot_id' => $depot->id,
            'code' => 'DAY',
            'starts_at' => '06:00:00',
            'duration_minutes' => 600,
            'prestart_minutes' => 15,
            'cleaning_minutes' => 45,
        ]);

        foreach ([1, 2, 3, 4, 5] as $weekday) {
            DepotShiftPattern::factory()->create([
                'shift_id' => $shift->id,
                'weekday' => $weekday,
                'duration_minutes' => 600,
            ]);
        }

        OeeSetting::factory()->forShift($shift)->create([
            'effective_from' => '2026-01-01',
            'performance_percent' => 100,
            'availability_percent' => 100,
            'prestart_minutes' => 15,
            'in_shift_cleaning_minutes' => 45,
        ]);

        MachineAsset::factory()->create(['depot_id' => $depot->id, 'code' => 'MEL-BIDI36']);

        return $depot->fresh(['shifts.patterns', 'machines']);
    }

    private function sku(string $code, float $ratePerHour, int $changeoverMinutes = 0): SkuMaster
    {
        $sku = SkuMaster::factory()->create([
            'sku_code' => $code,
            'standard_run_rate_per_hour' => $ratePerHour,
            'unit_weight_tonnes' => 0.25,
        ]);

        MachineSkuRunRate::query()->create([
            'machine_asset_id' => $this->machine->id,
            'sku_master_id' => $sku->id,
            'rated_per_hour' => $ratePerHour,
            'changeover_minutes' => $changeoverMinutes,
        ]);

        return $sku;
    }

    private function order(SkuMaster $sku, int $quantity, string $due): void
    {
        $this->liveOrder($this->depot, $sku, $quantity, $due);
    }

    private function salesOf(SkuMaster $sku, int $monthlyEa): void
    {
        foreach (range(1, 3) as $monthsBack) {
            $this->liveSale(
                $this->depot,
                $sku,
                $this->monday->startOfMonth()->subMonths($monthsBack)->toDateString(),
                $monthlyEa,
            );
        }
    }

    private function stockOf(SkuMaster $sku, int $prime): void
    {
        $this->liveStock($this->depot, $sku, $prime);
    }

    private function existingLine(SkuMaster $sku, int $quantity, bool $locked, string $origin): ProductionSchedule
    {
        return ProductionSchedule::query()->create([
            'depot_id' => $this->depot->id,
            'shift_id' => $this->depot->shifts->first()->id,
            'machine_asset_id' => $this->machine->id,
            'sku_master_id' => $sku->id,
            'schedule_date' => '2026-09-07',
            'planned_start_at' => '2026-09-07 06:00:00',
            'planned_end_at' => '2026-09-07 08:00:00',
            'planned_quantity' => $quantity,
            'shift_fraction' => 0.2,
            'effective_hours' => 1.8,
            'origin' => $origin,
            'is_locked' => $locked,
        ]);
    }
}
