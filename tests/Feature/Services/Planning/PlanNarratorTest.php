<?php

namespace Tests\Feature\Services\Planning;

use App\Models\Depot;
use App\Models\PlanRun;
use App\Models\ProductionSchedule;
use App\Models\SkuMaster;
use App\Services\Planning\PlanNarrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\SeedsLiveOrderBook;
use Tests\TestCase;

/**
 * The narrative is advisory: it explains the plan and never writes a quantity. So what matters is
 * that the brief carries the facts a planner needs, that the plan is unaffected when the call fails,
 * and that the plan's own lines are never sent.
 */
class PlanNarratorTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLiveOrderBook;

    private Depot $kilmore;

    private Depot $melton;

    private PlanRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        config(['claude.api_key' => 'test-key', 'claude.model' => 'claude-opus-5']);

        $this->installLiveOrderBook();
        $this->kilmore = Depot::factory()->create([
            'code' => 'KIL', 'name' => 'Kilmore', 'erp_entity_code' => 'RCPVIC',
            'prime_stock_locations' => ['32'],
        ]);
        $this->melton = Depot::factory()->create([
            'code' => 'MEL', 'name' => 'Melton', 'erp_entity_code' => 'RCPVIC',
            'prime_stock_locations' => ['31'],
        ]);

        $this->run = PlanRun::factory()->create([
            'horizon_from' => '2026-09-21',
            'horizon_to' => '2026-12-31',
            'as_of_date' => '2026-09-21',
            'depot_ids' => [$this->kilmore->id, $this->melton->id],
            'metrics' => [
                'lines' => 255,
                'planned_eaches' => 39_852,
                'planned_tonnes' => 20_945.8,
                'wire_tonnes' => 1_002.4,
                'concrete_m3' => 9_870.0,
                'by_depot' => [
                    'KIL' => [
                        'planned_eaches' => 32_509, 'shift_slots' => 148, 'shift_slots_used' => 148,
                        'unmet_order_eaches' => 10_223,
                    ],
                    'MEL' => [
                        'planned_eaches' => 7_343, 'shift_slots' => 296, 'shift_slots_used' => 81,
                        'unmet_order_eaches' => 14_030,
                    ],
                ],
            ],
        ]);
    }

    /**
     * A plant at every shift it has while another sits idle is the most decision-worthy fact in a
     * plan, so utilisation is computed rather than left for Claude to divide.
     */
    public function test_the_brief_includes_the_schedulers_active_rules(): void
    {
        $brief = app(PlanNarrator::class)->briefFor($this->run);

        $this->assertContains(
            'max_runs_per_shift',
            collect($brief['scheduling_rules'])->pluck('code')->all(),
        );
    }

    public function test_the_brief_spells_out_capacity_utilisation_per_plant(): void
    {
        $brief = app(PlanNarrator::class)->briefFor($this->run);

        $this->assertSame(100, $brief['by_plant']['KIL']['shift_utilisation_percent']);
        $this->assertSame(27, $brief['by_plant']['MEL']['shift_utilisation_percent']);
        $this->assertSame('Kilmore', $brief['by_plant']['KIL']['plant']);
    }

    public function test_the_brief_totals_the_ordered_work_that_did_not_fit(): void
    {
        $brief = app(PlanNarrator::class)->briefFor($this->run);

        $this->assertSame(24_253, $brief['totals']['unmet_ordered_pipes']);
        $this->assertSame(39_852, $brief['totals']['pipes']);
        $this->assertSame(1_002.4, $brief['totals']['wire_tonnes']);
    }

    /**
     * A shortfall means something to a customer, so the oldest unfilled orders travel with it.
     */
    public function test_the_brief_names_the_most_overdue_orders(): void
    {
        $sku = SkuMaster::factory()->create(['sku_code' => 'R0600-4']);

        $this->liveOrder($this->kilmore, $sku, quantity: 648, due: '2026-01-30', location: '32');
        $this->liveOrder($this->kilmore, $sku, quantity: 10, due: '2027-06-01', location: '32');

        $brief = app(PlanNarrator::class)->briefFor($this->run);

        $this->assertCount(1, $brief['most_overdue_orders'], 'Only orders due before the horizon are overdue.');
        $this->assertSame('2026-01-30', $brief['most_overdue_orders'][0]['due']);
        $this->assertSame(648, $brief['most_overdue_orders'][0]['pipes']);
    }

    public function test_the_brief_says_how_much_work_each_reason_accounts_for(): void
    {
        $sku = SkuMaster::factory()->create();

        foreach ([['covers_order', 300], ['covers_order', 40], ['replenish_fast_mover', 120]] as [$reason, $quantity]) {
            ProductionSchedule::factory()->create([
                'plan_run_id' => $this->run->id, 'depot_id' => $this->kilmore->id,
                'sku_master_id' => $sku->id, 'reason_code' => $reason, 'planned_quantity' => $quantity,
            ]);
        }

        $brief = app(PlanNarrator::class)->briefFor($this->run);

        $this->assertSame(
            ['covers_order' => 340, 'replenish_fast_mover' => 120],
            $brief['reasons_used'],
        );
    }

    /**
     * A plan is hundreds of lines. Sending them would cost a great deal to tell Claude what the
     * summary already says, so only the summary goes.
     */
    public function test_the_plans_own_lines_are_never_sent(): void
    {
        $sku = SkuMaster::factory()->create(['sku_code' => 'SHOULD-NOT-BE-SENT']);

        ProductionSchedule::factory()->create([
            'plan_run_id' => $this->run->id, 'depot_id' => $this->kilmore->id,
            'sku_master_id' => $sku->id, 'reason_code' => 'covers_order',
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'Kilmore is full.']],
            ]),
        ]);

        app(PlanNarrator::class)->narrate($this->run);

        Http::assertSent(function ($request): bool {
            $body = json_encode($request->data());

            $this->assertStringNotContainsString('SHOULD-NOT-BE-SENT', $body);

            return true;
        });
    }

    public function test_the_narrative_is_written_onto_the_run(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [
                    ['type' => 'thinking', 'thinking' => ''],
                    ['type' => 'text', 'text' => 'Kilmore is at every shift it has.'],
                ],
            ]),
        ]);

        $narrative = app(PlanNarrator::class)->narrate($this->run);

        $this->assertSame('Kilmore is at every shift it has.', $narrative);
        $this->assertSame('Kilmore is at every shift it has.', $this->run->fresh()->narrative);
    }

    public function test_the_request_asks_for_the_configured_model_and_effort(): void
    {
        config(['claude.effort' => 'medium']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'Fine.']],
            ]),
        ]);

        app(PlanNarrator::class)->narrate($this->run);

        Http::assertSent(fn ($request): bool => $request['model'] === 'claude-opus-5'
            && $request['output_config']['effort'] === 'medium'
            && $request->hasHeader('anthropic-version')
            && $request->hasHeader('x-api-key', 'test-key'));
    }

    /**
     * A refusal comes back as HTTP 200 with no usable content, so the stop reason has to be read
     * before the content is.
     */
    public function test_a_refusal_leaves_the_plan_without_a_narrative_rather_than_writing_nonsense(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'refusal',
                'stop_details' => ['type' => 'refusal', 'category' => 'cyber'],
                'content' => [],
            ]),
        ]);

        $this->assertNull(app(PlanNarrator::class)->narrate($this->run));
        $this->assertNull($this->run->fresh()->narrative);
    }

    public function test_a_failed_call_leaves_the_plan_untouched(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

        $this->assertNull(app(PlanNarrator::class)->narrate($this->run));
        $this->assertNull($this->run->fresh()->narrative);
    }

    public function test_a_connection_failure_leaves_the_plan_untouched(): void
    {
        Http::fake(fn () => throw new ConnectionException('No route to host'));

        $this->assertNull(app(PlanNarrator::class)->narrate($this->run));
        $this->assertNull($this->run->fresh()->narrative);
    }

    /**
     * The narrative is advisory, so an unconfigured key is not an error and costs no request.
     */
    public function test_without_an_api_key_nothing_is_sent(): void
    {
        config(['claude.api_key' => null]);
        Http::fake();

        $this->assertNull(app(PlanNarrator::class)->narrate($this->run));

        Http::assertNothingSent();
    }
}
