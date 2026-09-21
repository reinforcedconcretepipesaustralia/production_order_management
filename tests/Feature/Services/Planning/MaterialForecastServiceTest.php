<?php

namespace Tests\Feature\Services\Planning;

use App\Models\Depot;
use App\Models\MaterialRequirement;
use App\Models\PlanRun;
use App\Models\ProductionSchedule;
use App\Models\SkuMaster;
use App\Models\SkuWireRequirement;
use App\Services\Planning\MaterialForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The steel figure is what wire gets ordered against, so the rollup is pinned down here: every gauge
 * a pipe uses, bucketed into the week it is made in, and recomputed cleanly when a plan changes.
 */
class MaterialForecastServiceTest extends TestCase
{
    use RefreshDatabase;

    private Depot $depot;

    private PlanRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->depot = Depot::factory()->create(['code' => 'MEL']);
        $this->run = PlanRun::factory()->create([
            'horizon_from' => '2026-09-21',
            'horizon_to' => '2026-10-04',
            'depot_ids' => [$this->depot->id],
        ]);
    }

    public function test_every_gauge_a_pipe_uses_is_forecast(): void
    {
        $sku = $this->sku(['WIREPL4.95' => '4.387', 'WIREPL6.23' => '24.359']);
        $this->schedule($sku, '2026-09-21', 100);

        $forecast = app(MaterialForecastService::class)->forPlanRun($this->run);

        $this->assertSame(
            ['WIREPL6.23' => 2435.9, 'WIREPL4.95' => 438.7],
            $forecast->wireByGauge()->all(),
        );
        $this->assertSame(2874.6, $forecast->totalWireKg());
        $this->assertSame(2.875, $forecast->totalWireTonnes());
    }

    public function test_requirements_are_bucketed_into_the_week_the_pipes_are_made(): void
    {
        $sku = $this->sku(['WIREPL6.23' => '10.000']);
        // Monday of one week, and the Monday after.
        $this->schedule($sku, '2026-09-21', 100);
        $this->schedule($sku, '2026-09-23', 50);
        $this->schedule($sku, '2026-09-28', 200);

        $forecast = app(MaterialForecastService::class)->forPlanRun($this->run);

        $this->assertSame(
            ['2026-09-21' => 1500.0, '2026-09-28' => 2000.0],
            $forecast->wireKgByWeek()->all(),
        );
    }

    public function test_concrete_volume_and_mix_come_out_of_the_same_run(): void
    {
        $sku = $this->sku(['WIREPL6.23' => '10.000'], volume: '0.3086', mix: 'MEL-BIDI');
        $this->schedule($sku, '2026-09-21', 100);

        $forecast = app(MaterialForecastService::class)->forPlanRun($this->run);

        $this->assertSame(['MEL-BIDI' => 30.86], $forecast->concreteByMix()->all());
        $this->assertSame(30.86, $forecast->totalConcreteM3());
    }

    /**
     * A pipe whose bill records a volume but no mix code still has concrete to batch for, and saying
     * so is better than dropping the demand.
     */
    public function test_a_volume_with_no_mix_code_is_still_forecast(): void
    {
        $sku = $this->sku([], volume: '0.5000', mix: null);
        $this->schedule($sku, '2026-09-21', 10);

        $forecast = app(MaterialForecastService::class)->forPlanRun($this->run);

        $this->assertSame(['MIX (unspecified)' => 5.0], $forecast->concreteByMix()->all());
    }

    public function test_requirements_are_persisted_against_the_run(): void
    {
        $sku = $this->sku(['WIREPL6.23' => '10.000'], volume: '0.3000', mix: 'MEL-BIDI');
        $this->schedule($sku, '2026-09-21', 100);

        app(MaterialForecastService::class)->forPlanRun($this->run);

        $wire = MaterialRequirement::query()->wire()->sole();

        $this->assertSame($this->run->id, $wire->plan_run_id);
        $this->assertSame($this->depot->id, $wire->depot_id);
        $this->assertSame('2026-09-21', $wire->week_starting->toDateString());
        $this->assertSame('WIREPL6.23', $wire->material_code);
        $this->assertSame('kg', $wire->unit);
        $this->assertSame('1000.0000', $wire->quantity);
        $this->assertSame(100, $wire->planned_pipes);

        $this->assertSame('m3', MaterialRequirement::query()->concrete()->sole()->unit);
    }

    /**
     * A regenerated plan must not leave a stale week behind for a buyer to order against.
     */
    public function test_recomputing_replaces_the_previous_requirements(): void
    {
        $sku = $this->sku(['WIREPL6.23' => '10.000']);
        $schedule = $this->schedule($sku, '2026-09-21', 100);

        app(MaterialForecastService::class)->forPlanRun($this->run);

        $schedule->update(['planned_quantity' => 40]);

        $forecast = app(MaterialForecastService::class)->forPlanRun($this->run);

        $this->assertSame(1, MaterialRequirement::query()->wire()->count());
        $this->assertSame(400.0, $forecast->totalWireKg());
    }

    public function test_a_plan_with_nothing_to_make_needs_nothing(): void
    {
        $forecast = app(MaterialForecastService::class)->forPlanRun($this->run);

        $this->assertTrue($forecast->isEmpty());
        $this->assertSame(0.0, $forecast->totalWireKg());
        $this->assertSame(0, MaterialRequirement::query()->count());
    }

    /**
     * Tens of thousands of pipes times a three-decimal weight: summed as floating-point kilograms the
     * total drifts, so the rollup works in grams.
     */
    public function test_a_large_rollup_does_not_drift(): void
    {
        $sku = $this->sku(['WIREPL6.23' => '24.359']);

        for ($day = 21; $day <= 25; $day++) {
            $this->schedule($sku, '2026-09-'.$day, 9_999);
        }

        $forecast = app(MaterialForecastService::class)->forPlanRun($this->run);

        // 24.359 kg x 9,999 pipes x 5 days, exactly.
        $this->assertSame(1_217_828.205, $forecast->totalWireKg());
    }

    /**
     * @param  array<string, string>  $gauges
     */
    private function sku(array $gauges, string $volume = '0', ?string $mix = null): SkuMaster
    {
        $sku = SkuMaster::factory()->create([
            'concrete_volume_m3' => $volume,
            'concrete_mix_code' => $mix,
        ]);

        foreach ($gauges as $wireCode => $kgPerPipe) {
            SkuWireRequirement::factory()->create([
                'sku_master_id' => $sku->id,
                'wire_code' => $wireCode,
                'kg_per_pipe' => $kgPerPipe,
            ]);
        }

        return $sku;
    }

    private function schedule(SkuMaster $sku, string $date, int $quantity): ProductionSchedule
    {
        $day = CarbonImmutable::parse($date);

        return ProductionSchedule::factory()->create([
            'plan_run_id' => $this->run->id,
            'depot_id' => $this->depot->id,
            'sku_master_id' => $sku->id,
            'schedule_date' => $date,
            'planned_start_at' => $day->addHours(6),
            'planned_end_at' => $day->addHours(14),
            'planned_quantity' => $quantity,
        ]);
    }
}
