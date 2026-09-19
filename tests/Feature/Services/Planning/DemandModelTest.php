<?php

namespace Tests\Feature\Services\Planning;

use App\Models\DemandForecast;
use App\Models\Depot;
use App\Models\InventorySoh;
use App\Models\SalesHistoryMonthly;
use App\Models\SalesOrder;
use App\Models\SkuMaster;
use App\Services\Planning\DemandModel;
use App\Services\Planning\SkuDemandProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Reproduces the 'Pipes to Manufacture' sheet. Figures are asserted literally against what the
 * workbook's formulas produce for the same inputs.
 */
class DemandModelTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $asOf;

    private Depot $depot;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-month, so the current partial month is excluded from the velocity windows.
        $this->asOf = CarbonImmutable::parse('2026-09-15');
        $this->depot = Depot::factory()->create(['code' => 'MEL']);
    }

    public function test_velocity_averages_the_trailing_three_six_and_twelve_whole_months(): void
    {
        $sku = SkuMaster::factory()->create(['sku_code' => 'R0300-4']);

        // 12 whole months to Aug 2026 at 120/month, plus a partial September that must be
        // ignored — otherwise a half-finished month drags every average down.
        foreach (range(1, 12) as $monthsBack) {
            SalesHistoryMonthly::factory()->create([
                'depot_id' => $this->depot->id,
                'sku_master_id' => $sku->id,
                'month' => $this->asOf->startOfMonth()->subMonths($monthsBack)->toDateString(),
                'quantity_ea' => 120,
            ]);
        }
        SalesHistoryMonthly::factory()->create([
            'depot_id' => $this->depot->id,
            'sku_master_id' => $sku->id,
            'month' => $this->asOf->startOfMonth()->toDateString(),
            'quantity_ea' => 9_999,
        ]);

        $profile = $this->profiles()->get($sku->id);

        $this->assertSame(120.0, $profile->r3mMonthlyEa);
        $this->assertSame(120.0, $profile->r6mMonthlyEa);
        $this->assertSame(120.0, $profile->r12mMonthlyEa);
    }

    public function test_velocity_divides_by_the_window_not_by_months_with_sales(): void
    {
        $sku = SkuMaster::factory()->create();

        // A single month of 300 in the last three: R3M is 100, not 300. The workbook divides by
        // the window length, so a one-off project does not look like steady demand.
        SalesHistoryMonthly::factory()->create([
            'depot_id' => $this->depot->id,
            'sku_master_id' => $sku->id,
            'month' => '2026-07-01',
            'quantity_ea' => 300,
        ]);

        $profile = $this->profiles()->get($sku->id);

        $this->assertSame(100.0, $profile->r3mMonthlyEa);
        $this->assertSame(50.0, $profile->r6mMonthlyEa);
        $this->assertSame(25.0, $profile->r12mMonthlyEa);
    }

    public function test_order_book_is_bucketed_by_delivery_month_with_overdue_pulled_forward(): void
    {
        $sku = SkuMaster::factory()->create();

        $this->order($sku, quantity: 40, due: '2026-07-20');  // overdue
        $this->order($sku, quantity: 60, due: '2026-09-25');  // current month
        $this->order($sku, quantity: 30, due: '2026-10-10');
        $this->order($sku, quantity: 20, due: '2026-11-05');

        $profile = $this->profiles()->get($sku->id);

        $this->assertSame(150, $profile->outstandingQty);
        $this->assertSame(40, $profile->overdueQty);
        // Overdue work competes for this month's capacity, so it lands in the current bucket.
        $this->assertSame(100, $profile->currentMonthQty);
        $this->assertSame(
            ['2026-09-01' => 100, '2026-10-01' => 30, '2026-11-01' => 20],
            $profile->outstandingByMonth,
        );
        $this->assertSame('2026-07-20', $profile->earliestDueDate->toDateString());
    }

    public function test_order_book_counts_only_the_unallocated_remainder(): void
    {
        $sku = SkuMaster::factory()->create();
        $this->order($sku, quantity: 100, due: '2026-09-25', allocated: 70);

        $this->assertSame(30, $this->profiles()->get($sku->id)->outstandingQty);
    }

    public function test_stock_splits_prime_from_rework_across_bins(): void
    {
        $sku = SkuMaster::factory()->create();

        InventorySoh::factory()->create([
            'depot_id' => $this->depot->id,
            'sku_master_id' => $sku->id,
            'bin_location' => '31',
            'prime_quantity' => 60,
            'rework_quantity' => 5,
        ]);
        InventorySoh::factory()->create([
            'depot_id' => $this->depot->id,
            'sku_master_id' => $sku->id,
            'bin_location' => '33',
            'prime_quantity' => 0,
            'rework_quantity' => 12,
        ]);

        $profile = $this->profiles()->get($sku->id);

        $this->assertSame(60, $profile->primeSohQty);
        $this->assertSame(17, $profile->reworkSohQty);
    }

    public function test_coverage_figures_match_the_workbook_columns(): void
    {
        $sku = SkuMaster::factory()->create();
        $this->salesOf($sku, monthlyEa: 100, months: 12);
        $this->order($sku, quantity: 400, due: '2026-10-10');
        $this->stockOf($sku, prime: 90);

        $profile = $this->profiles()->get($sku->id);

        // Column AA: prime stock less the whole order book.
        $this->assertSame(-310, $profile->netPositionQty());
        // Column AJ: 90 in stock at 100/month.
        $this->assertSame(0.9, $profile->monthsOnHand());
        // Column U: 400 on order at 100/month is four months of normal sales.
        $this->assertSame(4.0, $profile->equivalentMonths());
        // Above three months, so Sales should be asked whether demand is rising.
        $this->assertTrue($profile->hasDemandSignal());
    }

    public function test_demand_signal_stays_off_at_or_below_three_months(): void
    {
        $sku = SkuMaster::factory()->create();
        $this->salesOf($sku, monthlyEa: 100, months: 12);
        $this->order($sku, quantity: 300, due: '2026-10-10');

        $this->assertFalse($this->profiles()->get($sku->id)->hasDemandSignal());
    }

    public function test_order_backed_quantity_is_what_stock_cannot_cover(): void
    {
        $covered = SkuMaster::factory()->create();
        $this->order($covered, quantity: 50, due: '2026-09-25');
        $this->stockOf($covered, prime: 80);

        $short = SkuMaster::factory()->create();
        $this->order($short, quantity: 200, due: '2026-09-25');
        $this->stockOf($short, prime: 80);

        $profiles = $this->profiles();

        $this->assertSame(0, $profiles->get($covered->id)->orderBackedQty());
        $this->assertFalse($profiles->get($covered->id)->hasOrderBackedDemand());
        $this->assertSame(120, $profiles->get($short->id)->orderBackedQty());
    }

    public function test_to_make_this_month_follows_the_workbook_column(): void
    {
        $sku = SkuMaster::factory()->create();
        $this->order($sku, quantity: 408, due: '2026-09-25');
        $this->stockOf($sku, prime: 90);

        // Workbook row 7: month-1 orders 408 less 90 in stock leaves 318 to make.
        $this->assertSame(318, $this->profiles()->get($sku->id)->toMakeThisMonthQty());
    }

    public function test_fast_movers_are_the_top_eighty_percent_of_recent_eaches(): void
    {
        $big = SkuMaster::factory()->create(['sku_code' => 'R0300-4']);
        $medium = SkuMaster::factory()->create(['sku_code' => 'R0375-4']);
        $small = SkuMaster::factory()->create(['sku_code' => 'R0450-4']);
        $tail = SkuMaster::factory()->create(['sku_code' => 'R2100-2']);

        $this->salesOf($big, monthlyEa: 600, months: 3);
        $this->salesOf($medium, monthlyEa: 200, months: 3);
        $this->salesOf($small, monthlyEa: 150, months: 3);
        $this->salesOf($tail, monthlyEa: 50, months: 3);

        $profiles = $this->profiles();

        // 600 of 1000 is 60%; adding 200 reaches 80% and is still a fast mover. Everything
        // after that point is made to order.
        $this->assertSame('A', $profiles->get($big->id)->abcClass);
        $this->assertSame('A', $profiles->get($medium->id)->abcClass);
        $this->assertSame('B', $profiles->get($small->id)->abcClass);
        $this->assertSame('B', $profiles->get($tail->id)->abcClass);
    }

    public function test_a_single_dominant_seller_is_still_a_fast_mover(): void
    {
        $dominant = SkuMaster::factory()->create();
        $other = SkuMaster::factory()->create();
        $this->salesOf($dominant, monthlyEa: 950, months: 3);
        $this->salesOf($other, monthlyEa: 50, months: 3);

        $profiles = $this->profiles();

        $this->assertSame('A', $profiles->get($dominant->id)->abcClass);
        $this->assertSame('B', $profiles->get($other->id)->abcClass);
    }

    public function test_skus_with_no_recent_sales_are_never_fast_movers(): void
    {
        $sku = SkuMaster::factory()->create();
        $this->order($sku, quantity: 10, due: '2026-09-25');

        $this->assertSame('C', $this->profiles()->get($sku->id)->abcClass);
    }

    public function test_replenishment_only_applies_to_fast_movers(): void
    {
        $fast = SkuMaster::factory()->create(['target_months_on_hand' => 1]);
        $slow = SkuMaster::factory()->create(['target_months_on_hand' => 1]);
        $this->salesOf($fast, monthlyEa: 900, months: 3);
        $this->salesOf($slow, monthlyEa: 100, months: 3);

        $profiles = $this->profiles();

        // A fast mover with nothing in the yard needs a month of cover built.
        $this->assertSame(900, $profiles->get($fast->id)->replenishmentQty(1.0));
        // A slower mover is made to order, whatever target it carries.
        $this->assertSame('B', $profiles->get($slow->id)->abcClass);
        $this->assertSame(0, $profiles->get($slow->id)->replenishmentQty(1.0));
    }

    public function test_replenishment_counts_only_stock_left_after_the_order_book(): void
    {
        $sku = SkuMaster::factory()->create();
        $this->salesOf($sku, monthlyEa: 100, months: 3);
        $this->stockOf($sku, prime: 150);
        $this->order($sku, quantity: 120, due: '2026-09-25');

        $profile = $this->profiles()->get($sku->id);

        // 150 in stock less 120 owed leaves 30 towards a 100 target, so 70 to build.
        $this->assertSame(70, $profile->replenishmentQty(1.0));
    }

    public function test_replenishment_is_zero_once_the_target_is_met(): void
    {
        $sku = SkuMaster::factory()->create();
        $this->salesOf($sku, monthlyEa: 100, months: 3);
        $this->stockOf($sku, prime: 250);

        $this->assertSame(0, $this->profiles()->get($sku->id)->replenishmentQty(1.0));
    }

    public function test_sku_target_overrides_the_default_months_on_hand(): void
    {
        $sku = SkuMaster::factory()->create(['target_months_on_hand' => 2.5]);
        $this->salesOf($sku, monthlyEa: 100, months: 3);

        $this->assertSame(250, $this->profiles()->get($sku->id)->replenishmentQty(1.0));
    }

    public function test_a_snapshot_is_persisted_so_a_plan_can_be_reproduced(): void
    {
        $sku = SkuMaster::factory()->create();
        $this->salesOf($sku, monthlyEa: 100, months: 12);
        $this->order($sku, quantity: 400, due: '2026-10-10');
        $this->stockOf($sku, prime: 90, rework: 8);

        $model = app(DemandModel::class);
        $profiles = $model->forDepot($this->depot, $this->asOf);

        $this->assertSame(1, $model->persist($profiles));

        $forecast = DemandForecast::query()->sole();

        $this->assertSame($this->depot->id, $forecast->depot_id);
        $this->assertSame('2026-09-15', $forecast->as_of_date->toDateString());
        $this->assertSame('100.0000', $forecast->r12m_monthly_ea);
        $this->assertSame(400, $forecast->outstanding_qty);
        $this->assertSame(90, $forecast->prime_soh_qty);
        $this->assertSame(8, $forecast->rework_soh_qty);
        $this->assertSame(-310, $forecast->net_position_qty);
        $this->assertSame('4.0000', $forecast->equivalent_months);
        $this->assertTrue($forecast->has_demand_signal);
        $this->assertSame(310, $forecast->order_backed_qty);
    }

    public function test_persisting_twice_for_the_same_day_updates_rather_than_duplicates(): void
    {
        $sku = SkuMaster::factory()->create();
        $this->stockOf($sku, prime: 10);

        $model = app(DemandModel::class);
        $model->persist($model->forDepot($this->depot, $this->asOf));
        $this->stockOf($sku, prime: 25, bin: '32');
        $model->persist($model->forDepot($this->depot, $this->asOf));

        $this->assertSame(1, DemandForecast::query()->count());
        $this->assertSame(35, DemandForecast::query()->sole()->prime_soh_qty);
    }

    public function test_classification_can_be_written_back_onto_the_skus(): void
    {
        $fast = SkuMaster::factory()->create();
        $slow = SkuMaster::factory()->create();
        $this->salesOf($fast, monthlyEa: 900, months: 3);
        $this->salesOf($slow, monthlyEa: 100, months: 3);

        $model = app(DemandModel::class);
        $model->applyClassificationToSkus($model->forDepot($this->depot, $this->asOf));

        $this->assertTrue($fast->fresh()->isMakeToStock());
        $this->assertFalse($slow->fresh()->isMakeToStock());
    }

    public function test_another_plants_sales_stock_and_orders_are_excluded(): void
    {
        $other = Depot::factory()->create(['code' => 'KIL']);
        $sku = SkuMaster::factory()->create();

        SalesHistoryMonthly::factory()->create([
            'depot_id' => $other->id,
            'sku_master_id' => $sku->id,
            'month' => '2026-08-01',
            'quantity_ea' => 500,
        ]);
        SalesOrder::factory()->create([
            'depot_id' => $other->id,
            'sku_master_id' => $sku->id,
            'ordered_quantity' => 500,
            'allocated_quantity' => 0,
            'status' => 'open',
        ]);

        $this->assertTrue($this->profiles()->isEmpty());
    }

    public function test_a_plant_with_no_data_yields_no_profiles(): void
    {
        $this->assertTrue($this->profiles()->isEmpty());
    }

    /**
     * @return Collection<int, SkuDemandProfile>
     */
    private function profiles(): Collection
    {
        return app(DemandModel::class)->forDepot($this->depot, $this->asOf);
    }

    private function salesOf(SkuMaster $sku, int $monthlyEa, int $months): void
    {
        foreach (range(1, $months) as $monthsBack) {
            SalesHistoryMonthly::factory()->create([
                'depot_id' => $this->depot->id,
                'sku_master_id' => $sku->id,
                'month' => $this->asOf->startOfMonth()->subMonths($monthsBack)->toDateString(),
                'quantity_ea' => $monthlyEa,
            ]);
        }
    }

    private function order(SkuMaster $sku, int $quantity, string $due, int $allocated = 0): void
    {
        SalesOrder::factory()->create([
            'depot_id' => $this->depot->id,
            'sku_master_id' => $sku->id,
            'ordered_quantity' => $quantity,
            'allocated_quantity' => $allocated,
            'requested_delivery_date' => $due,
            'status' => $allocated > 0 ? 'partially_allocated' : 'open',
        ]);
    }

    private function stockOf(SkuMaster $sku, int $prime, int $rework = 0, string $bin = '31'): void
    {
        InventorySoh::factory()->create([
            'depot_id' => $this->depot->id,
            'sku_master_id' => $sku->id,
            'bin_location' => $bin,
            'prime_quantity' => $prime,
            'rework_quantity' => $rework,
        ]);
    }
}
