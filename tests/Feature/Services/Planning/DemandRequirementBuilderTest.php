<?php

namespace Tests\Feature\Services\Planning;

use App\Models\Depot;
use App\Models\SkuMaster;
use App\Services\Planning\DemandModel;
use App\Services\Planning\DemandRequirement;
use App\Services\Planning\DemandRequirementBuilder;
use App\Services\Planning\SkuDemandProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Fixtures\SeedsLiveOrderBook;
use Tests\TestCase;

/**
 * The rule the planners work to: nothing without a backorder gets scheduled until the plant has
 * caught up with demand, and only then does it build safety stock, fastest movers first.
 *
 * These assertions are the guard on that rule. A weighted score that let a high-velocity
 * replenishment line creep ahead of a late order would pass a looser test and be wrong.
 */
class DemandRequirementBuilderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLiveOrderBook;

    private CarbonImmutable $asOf;

    private Depot $depot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asOf = CarbonImmutable::parse('2026-09-15');
        $this->installLiveOrderBook();
        $this->depot = Depot::factory()->create([
            'code' => 'MEL',
            'erp_entity_code' => 'RCPVIC',
            'prime_stock_locations' => ['31'],
        ]);
    }

    public function test_order_backed_work_always_outranks_replenishment(): void
    {
        // The fastest seller by a wide margin, fully covered by stock — so it has no backorder.
        $fastButCovered = SkuMaster::factory()->create(['sku_code' => 'R0300-4']);
        $this->salesOf($fastButCovered, monthlyEa: 900);
        $this->stockOf($fastButCovered, prime: 50);

        // A slow seller with an order nobody can fill.
        $slowButOwed = SkuMaster::factory()->create(['sku_code' => 'R2100-2']);
        $this->salesOf($slowButOwed, monthlyEa: 20);
        $this->order($slowButOwed, quantity: 15, due: '2026-10-20');

        $requirements = $this->build();

        $this->assertSame(
            [DemandRequirement::SOURCE_ORDER_BACKED, DemandRequirement::SOURCE_REPLENISHMENT],
            $requirements->map(fn (DemandRequirement $r): string => $r->source)->all(),
        );
        // The slow mover that owes a customer comes first, despite selling 45 times less.
        $this->assertSame('R2100-2', $requirements->first()->sku->sku_code);
    }

    public function test_overdue_orders_come_before_orders_merely_due(): void
    {
        $due = SkuMaster::factory()->create(['sku_code' => 'DUE-SOON']);
        $this->order($due, quantity: 100, due: '2026-09-20');

        $overdue = SkuMaster::factory()->create(['sku_code' => 'OVERDUE']);
        $this->order($overdue, quantity: 10, due: '2026-06-01');

        $requirements = $this->build();

        $this->assertSame(
            ['OVERDUE', 'DUE-SOON'],
            $requirements->map(fn (DemandRequirement $r): string => $r->sku->sku_code)->all(),
        );
        $this->assertTrue($requirements->first()->isOverdue());
        $this->assertSame('order_overdue', $requirements->first()->reasonCode);
        $this->assertSame('order_due', $requirements->last()->reasonCode);
    }

    public function test_order_backed_lines_are_ranked_by_earliest_due_date(): void
    {
        foreach (['2026-11-01' => 'THIRD', '2026-09-25' => 'FIRST', '2026-10-10' => 'SECOND'] as $due => $code) {
            $sku = SkuMaster::factory()->create(['sku_code' => $code]);
            $this->order($sku, quantity: 50, due: $due);
        }

        $this->assertSame(
            ['FIRST', 'SECOND', 'THIRD'],
            $this->build()->map(fn (DemandRequirement $r): string => $r->sku->sku_code)->all(),
        );
    }

    public function test_replenishment_is_ranked_by_velocity_highest_first(): void
    {
        $biggest = SkuMaster::factory()->create(['sku_code' => 'BIGGEST']);
        $middle = SkuMaster::factory()->create(['sku_code' => 'MIDDLE']);
        $this->salesOf($biggest, monthlyEa: 600);
        $this->salesOf($middle, monthlyEa: 400);

        $requirements = $this->build();

        $this->assertTrue($requirements->every(fn (DemandRequirement $r): bool => ! $r->isOrderBacked()));
        $this->assertSame(
            ['BIGGEST', 'MIDDLE'],
            $requirements->map(fn (DemandRequirement $r): string => $r->sku->sku_code)->all(),
        );
    }

    public function test_slow_movers_never_generate_replenishment(): void
    {
        $fast = SkuMaster::factory()->create(['sku_code' => 'FAST']);
        $slow = SkuMaster::factory()->create(['sku_code' => 'SLOW']);
        $this->salesOf($fast, monthlyEa: 900);
        $this->salesOf($slow, monthlyEa: 100);

        $requirements = $this->build();

        // Only the fast mover is stocked; the slow one is made to order.
        $this->assertSame(['FAST'], $requirements->map(fn (DemandRequirement $r): string => $r->sku->sku_code)->all());
    }

    public function test_replenishment_can_be_withheld_entirely(): void
    {
        $sku = SkuMaster::factory()->create();
        $this->salesOf($sku, monthlyEa: 900);

        $requirements = app(DemandRequirementBuilder::class)
            ->build($this->profiles(), includeReplenishment: false);

        $this->assertTrue($requirements->isEmpty());
    }

    public function test_reports_whether_the_plant_has_caught_up_with_demand(): void
    {
        $builder = app(DemandRequirementBuilder::class);

        $sku = SkuMaster::factory()->create();
        $this->salesOf($sku, monthlyEa: 100);
        $this->order($sku, quantity: 300, due: '2026-10-10');
        $this->stockOf($sku, prime: 100);

        $this->assertFalse($builder->isDemandCaughtUp($this->profiles()));
        $this->assertSame(200, $builder->uncoveredOrderQty($this->profiles()));

        // Enough stock arrives to cover the order book.
        $this->stockOf($sku, prime: 250, bin: '31');

        $this->assertTrue($builder->isDemandCaughtUp($this->profiles()));
        $this->assertSame(0, $builder->uncoveredOrderQty($this->profiles()));
    }

    public function test_a_line_explains_itself_for_the_review_screen(): void
    {
        $owed = SkuMaster::factory()->create(['sku_code' => 'OWED']);
        $this->order($owed, quantity: 120, due: '2026-06-01');

        $requirement = $this->build()->first();

        $this->assertStringContainsString('Covers 120 on order', $requirement->explain());
        $this->assertStringContainsString('overdue', $requirement->explain());
        $this->assertSame(
            DemandRequirement::SOURCE_ORDER_BACKED,
            $requirement->context()['source'],
        );
        $this->assertSame('2026-06-01', $requirement->context()['due_date']);
    }

    public function test_a_replenishment_line_says_why_it_was_allowed(): void
    {
        $sku = SkuMaster::factory()->create();
        $this->salesOf($sku, monthlyEa: 240);

        $explanation = $this->build()->first()->explain();

        $this->assertStringContainsString('Safety stock for a fast mover', $explanation);
        $this->assertStringContainsString('order book is covered', $explanation);
    }

    public function test_quantities_carry_through_from_the_demand_profile(): void
    {
        $sku = SkuMaster::factory()->create();
        $this->salesOf($sku, monthlyEa: 100);
        $this->order($sku, quantity: 400, due: '2026-10-10');
        $this->stockOf($sku, prime: 90);

        $requirement = $this->build()->first();

        // 400 owed less 90 in stock.
        $this->assertSame(310, $requirement->quantity);
        $this->assertSame(100.0, $requirement->velocityMonthlyEa);
    }

    /**
     * @return Collection<int, DemandRequirement>
     */
    private function build(): Collection
    {
        return app(DemandRequirementBuilder::class)->build($this->profiles());
    }

    /**
     * @return Collection<int, SkuDemandProfile>
     */
    private function profiles(): Collection
    {
        return app(DemandModel::class)->forDepot($this->depot, $this->asOf);
    }

    private function salesOf(SkuMaster $sku, int $monthlyEa): void
    {
        foreach (range(1, 3) as $monthsBack) {
            $this->liveSale(
                $this->depot,
                $sku,
                $this->asOf->startOfMonth()->subMonths($monthsBack)->toDateString(),
                $monthlyEa,
            );
        }
    }

    private function order(SkuMaster $sku, int $quantity, string $due): void
    {
        $this->liveOrder($this->depot, $sku, $quantity, $due);
    }

    private function stockOf(SkuMaster $sku, int $prime, string $bin = '31'): void
    {
        $this->liveStock($this->depot, $sku, $prime, $bin);
    }
}
